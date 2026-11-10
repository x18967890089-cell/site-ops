<?php

/**
 * health-check.php — 小站上线后的例行体检（单文件、纯 PHP，无框架依赖）
 *
 * 用配置文件描述「你的站长什么样」，脚本按它去查：
 *   1. 数据库连得上吗、完整性检查过了吗、有没有该 VACUUM 的空闲页
 *   2. 每张表各多少行（一眼看出哪张表突然变空或暴增）
 *   3. 上传目录里有没有「库里有记录但文件没了」的孤儿
 *   4. 过期或缺失的备份
 *   5. 磁盘、扩展、时区这些一旦不对就会半夜出事的设置
 *
 * 用法：
 *   php bin/health-check.php --config=site-ops.json
 *   php bin/health-check.php --config=site-ops.json --json
 *
 * 退出码：0 全绿 / 1 有 problem（需要处理）/ 2 配置或参数错误
 */

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_PROBLEM = 1;
const EXIT_CONFIG = 2;

$problems = [];
$warnings = [];
$notes = [];

function say(string $label, string $value, string $mark = '  '): void
{
    // --json 时不许有人类可读输出：结果必须是单个 JSON 对象，能被 jq / ConvertFrom-Json 直接吃
    if (!empty($GLOBALS['JSON_MODE'])) {
        return;
    }
    printf("%s%-26s %s\n", $mark, $label, $value);
}

function problem(string $message): void
{
    global $problems;
    $problems[] = $message;
}

function warn(string $message): void
{
    global $warnings;
    $warnings[] = $message;
}

/**
 * @return array{config:string,json:bool}
 */
function args(array $argv): array
{
    $out = ['config' => 'site-ops.json', 'json' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $out['json'] = true;
            continue;
        }
        if (preg_match('/^--config=(.+)$/u', $arg, $m) === 1) {
            $out['config'] = $m[1];
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDERR, "用法: php bin/health-check.php [--config=site-ops.json] [--json]\n");
            exit(EXIT_OK);
        }
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(EXIT_CONFIG);
    }

    return $out;
}

$args = args($argv);
$GLOBALS['JSON_MODE'] = (bool)$args['json'];
if ($GLOBALS['JSON_MODE']) {
    ob_start(); // 人类可读输出全部丢弃，最终只 echo 一个 JSON 对象
}
$configPath = $args['config'];

if (!is_file($configPath)) {
    fwrite(STDERR, "找不到配置文件: {$configPath}\n");
    fwrite(STDERR, "从 site-ops.example.json 复制一份改吧。\n");
    exit(EXIT_CONFIG);
}

$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    fwrite(STDERR, "配置文件不是合法 JSON: {$configPath}\n");
    exit(EXIT_CONFIG);
}

$siteName = (string)($config['name'] ?? basename(dirname(realpath($configPath) ?: $configPath)));
$configDir = dirname(realpath($configPath) ?: $configPath);
// 配置里的相对路径一律相对「配置文件所在目录」，不是相对当前工作目录 ——
// 否则从别的目录跑一遍就全红了，这坑踩过不止一次。
$root = resolvePath((string)($config['root'] ?? '.'), $configDir);

/**
 * 相对路径 → 绝对路径（相对配置文件所在目录）。
 */
function resolvePath(string $path, string $base): string
{
    if ($path === '') {
        return $base;
    }
    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
        return $path;
    }

    $clean = preg_replace('#^(\./)+#', '', $path) ?? $path;
    $base = rtrim(str_replace('\\', '/', $base), '/');
    // root 写成 "." 时会拼出 "/.", 在这里就地抹掉
    while (substr($base, -2) === '/.') {
        $base = substr($base, 0, -2);
    }
    $joined = ($base === '' ? '' : $base . '/') . $clean;

    return preg_replace('#/\./+#', '/', $joined) ?? $joined;
}

// ---------------------------------------------------------------- 数据库
$pdo = null;
$dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
$driver = (string)($dbConfig['driver'] ?? 'sqlite');

echo "\n";
echo "========================================\n";
echo " {$siteName} 健康自检  " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

if ($dbConfig !== []) {
    echo "\n【数据库】\n";
    try {
        if ($driver === 'sqlite') {
            $path = (string)($dbConfig['path'] ?? '');
            $abs = resolvePath($path, $root);
            if (!is_file($abs)) {
                problem('SQLite 文件不存在：' . $abs);
                say('文件', '不存在 ' . $abs, '  ❌ ');
            } else {
                $size = (int)filesize($abs);
                say('文件', $abs);
                say('大小', number_format($size / 1024, 1) . ' KB');
                $pdo = new PDO('sqlite:' . $abs, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            }
        } else {
            $dsn = (string)($dbConfig['dsn'] ?? '');
            if ($dsn === '') {
                problem('db.driver=' . $driver . ' 但没有给 db.dsn');
            } else {
                $pdo = new PDO($dsn, (string)($dbConfig['user'] ?? ''), (string)($dbConfig['pass'] ?? ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                say('连接', $driver . ' ok', '  ✅ ');
            }
        }
    } catch (Throwable $e) {
        problem('数据库连接失败：' . $e->getMessage());
        say('连接', $e->getMessage(), '  ❌ ');
    }

    if ($pdo instanceof PDO && $driver === 'sqlite') {
        $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            problem('SQLite 完整性检查未通过：' . $integrity);
            say('完整性', $integrity, '  ❌ ');
        } else {
            say('完整性', 'ok', '  ✅ ');
        }

        $pages = (int)$pdo->query('PRAGMA page_count')->fetchColumn();
        $free = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
        say('页面 / 空闲页', $pages . ' / ' . $free);
        if ($pages > 0 && $free / $pages > 0.25) {
            warn('空闲页偏多（' . $free . '/' . $pages . '），可以跑一次 VACUUM');
        }
    }

    // 表行数
    if ($pdo instanceof PDO) {
        echo "\n【数据量】\n";
        $tables = [];
        if ($driver === 'sqlite') {
            $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        } else {
            $statement = $pdo->query('SHOW TABLES');
        }
        foreach ($statement ?: [] as $row) {
            $tables[] = (string)reset($row);
        }

        $only = is_array($config['tables'] ?? null) ? $config['tables'] : null;
        $totalRows = 0;
        foreach ($tables as $table) {
            if ($only !== null && !in_array($table, $only, true)) {
                continue;
            }
            $quoted = $driver === 'sqlite' ? '"' . str_replace('"', '""', $table) . '"' : '`' . str_replace('`', '``', $table) . '`';
            $count = (int)$pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
            $totalRows += $count;
            say($table, (string)$count);
        }
        say('合计', count($tables) . ' 张表 / ' . $totalRows . ' 行');
    }
}

// ---------------------------------------------------------------- 上传文件孤儿
$uploads = is_array($config['uploads'] ?? null) ? $config['uploads'] : null;
if ($uploads !== null && $pdo instanceof PDO) {
    echo "\n【上传文件】\n";
    $dir = (string)($uploads['dir'] ?? '');
    $abs = resolvePath($dir, $root);

    if (!is_dir($abs)) {
        warn('上传目录不存在：' . $abs);
        say('目录', '不存在 ' . $abs, '  ⚠️ ');
    } else {
        $columns = is_array($uploads['columns'] ?? null) ? $uploads['columns'] : [];
        $table = (string)($uploads['table'] ?? '');
        $missing = [];
        $checked = 0;

        if ($table !== '' && $columns !== []) {
            $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"', $columns))
                . ' FROM "' . str_replace('"', '""', $table) . '"';
            try {
                foreach ($pdo->query($sql) ?: [] as $row) {
                    foreach ($columns as $column) {
                        $name = (string)($row[$column] ?? '');
                        if ($name === '') {
                            continue;
                        }
                        $checked++;
                        if (!is_file(rtrim($abs, '/\\') . '/' . $name)) {
                            $missing[] = $name;
                        }
                    }
                }
            } catch (Throwable $e) {
                warn('孤儿检查的 SQL 跑不通：' . $e->getMessage());
                say('SQL', $e->getMessage(), '  ⚠️ ');
            }
        }

        if ($checked === 0) {
            say('孤儿检查', '跳过（没配 uploads.table/columns）');
        } elseif ($missing === []) {
            say('文件', $checked . ' 个引用，全部存在', '  ✅ ');
        } else {
            problem('有 ' . count($missing) . ' 个文件在库里被引用但磁盘上没有：' . implode('、', array_slice($missing, 0, 5)));
            say('缺失', count($missing) . ' 个', '  ❌ ');
        }

        $loose = 0;
        foreach (new DirectoryIterator($abs) as $entry) {
            if ($entry->isFile()) {
                $loose++;
            }
        }
        say('目录内文件数', (string)$loose);
    }
}

// ---------------------------------------------------------------- 备份
$backup = is_array($config['backup'] ?? null) ? $config['backup'] : [];
if ($backup !== []) {
    echo "\n【备份】\n";
    $dir = (string)($backup['dir'] ?? '');
    $abs = resolvePath($dir, $root);
    $maxAge = (int)($backup['max_age_hours'] ?? 48);

    if (!is_dir($abs)) {
        problem('备份目录不存在：' . $abs . '（先跑一次 bin/backup-now.php）');
        say('目录', '不存在 ' . $abs, '  ❌ ');
    } else {
        $files = [];
        foreach (glob(rtrim($abs, '/\\') . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $files[] = ['path' => $file, 'time' => (int)filemtime($file), 'size' => (int)filesize($file)];
            }
        }
        if ($files === []) {
            problem('备份目录是空的：' . $abs);
            say('份数', '0', '  ❌ ');
        } else {
            usort($files, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
            $newest = $files[0];
            $total = array_sum(array_column($files, 'size'));
            say('份数', (string)count($files), '  ✅ ');
            say('最近一份', date('Y-m-d H:i', $newest['time']) . '（' . number_format($newest['size'] / 1024, 1) . ' KB）');
            say('占用', number_format($total / 1024 / 1024, 2) . ' MB');

            $ageHours = (time() - $newest['time']) / 3600;
            if ($ageHours > $maxAge) {
                problem(sprintf('最近一份备份已经是 %.1f 小时前（阈值 %d 小时）', $ageHours, $maxAge));
                say('新鲜度', sprintf('%.1f 小时前', $ageHours), '  ❌ ');
            } else {
                say('新鲜度', sprintf('%.1f 小时前', $ageHours), '  ✅ ');
            }
        }
    }
}

// ---------------------------------------------------------------- 运行环境
echo "\n【运行环境】\n";
say('PHP', PHP_VERSION . ' / ' . PHP_SAPI);

$required = is_array($config['require_extensions'] ?? null) ? $config['require_extensions'] : [];
foreach ($required as $extension) {
    if (extension_loaded((string)$extension)) {
        say('扩展 ' . $extension, 'ok', '  ✅ ');
    } else {
        problem('缺少 PHP 扩展：' . $extension);
        say('扩展 ' . $extension, '未加载', '  ❌ ');
    }
}

say('时区', date_default_timezone_get() . '（现在 ' . date('H:i') . '）');

$diskPath = $root;
$freeBytes = @disk_free_space($diskPath);
if (is_float($freeBytes) || is_int($freeBytes)) {
    $freeGb = $freeBytes / 1024 / 1024 / 1024;
    say('磁盘可用', sprintf('%.1f GB', $freeGb));
    if ($freeGb < 1.0) {
        problem('磁盘可用空间不足 1 GB');
    }
}

// 配置项的自查：把「上线前必须改」的开关写成配置
$flags = is_array($config['flags'] ?? null) ? $config['flags'] : [];
foreach ($flags as $name => $expected) {
    $actual = getenv((string)$name);
    if ($actual === false) {
        $notes[] = '环境变量 ' . $name . ' 未设置（期望 ' . var_export($expected, true) . '）';
        say($name, '未设置', '  ℹ️ ');
        continue;
    }
    $ok = (string)$actual === (string)(is_bool($expected) ? ($expected ? '1' : '0') : $expected);
    if ($ok) {
        say($name, $actual, '  ✅ ');
    } else {
        problem('环境变量 ' . $name . '=' . $actual . '，期望 ' . var_export($expected, true));
        say($name, $actual, '  ❌ ');
    }
}

// ---------------------------------------------------------------- 结论
$result = [
    'ok' => $problems === [],
    'site' => $siteName,
    'checked_at' => date('c'),
    'problems' => $problems,
    'warnings' => $warnings,
    'notes' => $notes,
];

if ($args['json']) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($problems === [] ? EXIT_OK : EXIT_PROBLEM);
}

echo "\n========================================\n";
if ($problems === [] && $warnings === []) {
    echo " ✅ 一切正常\n";
} else {
    if ($problems !== []) {
        echo ' ❌ 需要处理（' . count($problems) . " 项）：\n";
        foreach ($problems as $index => $item) {
            echo '    ' . ($index + 1) . '. ' . $item . "\n";
        }
    }
    if ($warnings !== []) {
        echo "\n ⚠️ 建议留意（" . count($warnings) . " 项）：\n";
        foreach ($warnings as $index => $item) {
            echo '    ' . ($index + 1) . '. ' . $item . "\n";
        }
    }
}
echo "========================================\n\n";

exit($problems === [] ? EXIT_OK : EXIT_PROBLEM);
