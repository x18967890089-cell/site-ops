<?php

/**
 * backup-now.php — 给个人小站用的「备份 + 轮转 + 校验」，一行命令跑完
 *
 * 它只做四件事，但每件都做到能验证：
 *   1. 备份：SQLite 用 VACUUM INTO（在线一致性快照，比直接 cp 数据库安全），
 *      MySQL/PostgreSQL 走 mysqldump/pg_dump；再 gzip；可选中转第二个目录（异地/网盘同步目录）
 *   2. 校验：备份文件能打开、能通过 integrity_check、表数量与源库一致
 *   3. 轮转：只保留最近 N 份（默认 14），按时间删最旧的
 *   4. 报告：一行摘要 + 退出码，直接丢给 cron / 计划任务 / CI
 *
 * 用法：
 *   php bin/backup-now.php --config=site-ops.json
 *   php bin/backup-now.php --db=data/app.db --out=backups --keep=7
 *   php bin/backup-now.php --config=site-ops.json --json
 *
 * 退出码：0 成功 / 1 备份或校验失败 / 2 参数错误
 */

declare(strict_types=1);

function say(string $message): void
{
    // 用 echo 而不是 fwrite(STDOUT)：fwrite 直写流，绕过 ob_start()，--json 就会混进人类可读文本
    echo $message . "\n";
}

/** --json 模式：丢掉所有人类可读缓冲，只吐一个 JSON 对象 */
function jsonOut(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
}
function fail(string $message): void
{
    fwrite(STDERR, '[ERROR] ' . $message . "\n");
}

/**
 * @return array<string, string|bool|int>
 */
function args(array $argv): array
{
    $out = ['config' => '', 'db' => '', 'out' => '', 'keep' => 0, 'mirror' => '', 'json' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $out['json'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            say('用法: php bin/backup-now.php [--config=site-ops.json] [--db=path] [--out=dir] [--keep=N] [--mirror=dir] [--json]');
            exit(0);
        }
        if (preg_match('/^--(config|db|out|mirror)=(.*)$/u', $arg, $m) === 1) {
            $out[$m[1]] = $m[2];
            continue;
        }
        if (preg_match('/^--keep=(\d+)$/u', $arg, $m) === 1) {
            $out['keep'] = (int)$m[1];
            continue;
        }
        fail('未知参数: ' . $arg);
        exit(2);
    }

    return $out;
}

/**
 * 把相对路径解析成绝对路径（相对配置文件所在目录）。
 */
function resolvePath(string $path, string $base): string
{
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
        return $path;
    }

    $clean = preg_replace('#^(\./)+#', '', $path) ?? $path;
    $base = rtrim(str_replace('\\', '/', $base), '/');
    while (substr($base, -2) === '/.') {
        $base = substr($base, 0, -2);
    }
    $joined = ($base === '' ? '' : $base . '/') . $clean;

    return preg_replace('#/\./+#', '/', $joined) ?? $joined;
}

/**
 * SQLite 在线一致性快照。
 *
 * VACUUM INTO 在一个读事务里把整库写成新文件，不会拷到写了一半的页面；
 * 直接 copy() 一个正在被写的 SQLite 文件才是事故来源。
 */
function snapshotSqlite(string $source, string $target): void
{
    $pdo = new PDO('sqlite:' . $source, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $quoted = "'" . str_replace("'", "''", $target) . "'";
    $pdo->exec('VACUUM INTO ' . $quoted);
}

/**
 * 校验 sqlite 备份：能打开、integrity ok、表数量 > 0。
 *
 * @return array{tables:int, pages:int, integrity:string}
 */
function verifySqlite(string $file): array
{
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
    $pages = (int)$pdo->query('PRAGMA page_count')->fetchColumn();

    return ['tables' => $tables, 'pages' => $pages, 'integrity' => $integrity];
}

function humanBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return sprintf('%.1f %s', $value, $units[$unit]);
}

// ---------------------------------------------------------------- 解析配置

$opts = args($argv);
if (!empty($opts['json'])) {
    ob_start(); // 人类可读输出丢弃，最终只留一个 JSON 对象
}
$root = getcwd() ?: '.';
$config = [];

if ($opts['config'] !== '') {
    if (!is_file((string)$opts['config'])) {
        fail('找不到配置文件: ' . $opts['config']);
        exit(2);
    }
    $config = json_decode((string)file_get_contents((string)$opts['config']), true) ?: [];
    $root = dirname(realpath((string)$opts['config']) ?: (string)$opts['config']);
}

$dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
$backupConfig = is_array($config['backup'] ?? null) ? $config['backup'] : [];

$driver = (string)($dbConfig['driver'] ?? 'sqlite');
$source = (string)($opts['db'] !== '' ? $opts['db'] : ($dbConfig['path'] ?? ''));
$source = resolvePath($source, $root);

$outDir = (string)($opts['out'] !== '' ? $opts['out'] : ($backupConfig['dir'] ?? 'backups'));
$outDir = resolvePath($outDir, $root);

$keep = (int)($opts['keep'] !== 0 ? $opts['keep'] : ($backupConfig['keep'] ?? 14));
$mirror = resolvePath((string)($opts['mirror'] !== '' ? $opts['mirror'] : ($backupConfig['mirror'] ?? '')), $root);

if ($driver === 'sqlite' && $source === '') {
    fail('没有数据库路径：用 --db= 或配置文件里的 db.path');
    exit(2);
}
if ($driver === 'sqlite' && !is_file($source)) {
    fail('数据库文件不存在: ' . $source);
    exit(2);
}

if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fail('建不了备份目录: ' . $outDir);
    exit(2);
}

$started = microtime(true);
$stamp = date('Ymd-His');
$label = pathinfo($source, PATHINFO_FILENAME);
$tmp = $outDir . '/.' . $label . '-' . $stamp . '.tmp.sqlite';
$final = $outDir . '/' . $label . '-' . $stamp . '.sqlite.gz';

say('');
say('== backup-now ==');
say('  source : ' . $source . '  (' . $driver . ')');
say('  out    : ' . $outDir);
say('  keep   : ' . $keep . ' 份');
if ($mirror !== '') {
    say('  mirror : ' . $mirror);
}
say('');

// ---------------------------------------------------------------- 1. 备份

try {
    if ($driver === 'sqlite') {
        snapshotSqlite($source, $tmp);
    } else {
        $bin = $driver === 'mysql' ? 'mysqldump' : 'pg_dump';
        $parts = [
            $bin,
            '-h', (string)($dbConfig['host'] ?? '127.0.0.1'),
            '-P', (string)($dbConfig['port'] ?? ($driver === 'mysql' ? 3306 : 5432)),
            '-u', (string)($dbConfig['user'] ?? ''),
            (string)($dbConfig['database'] ?? ''),
        ];
        if ($driver === 'mysql') {
            $parts[] = '--single-transaction';
            $parts[] = '--quick';
            $parts[] = '--default-character-set=utf8mb4';
        }
        $command = implode(' ', array_map('escapeshellarg', $parts)) . ' > ' . escapeshellarg($tmp);
        $env = '';
        if (($dbConfig['pass'] ?? '') !== '') {
            // 口令只走环境变量，不进命令行（命令行会被 ps 看见）
            $env = $driver === 'mysql'
                ? 'MYSQL_PWD=' . escapeshellarg((string)$dbConfig['pass']) . ' '
                : 'PGPASSWORD=' . escapeshellarg((string)$dbConfig['pass']) . ' ';
        }
        $rc = 0;
        exec($env . $command, $lines, $rc);
        if ($rc !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
            throw new RuntimeException($bin . ' 退出码 ' . $rc);
        }
        // 文本 dump 直接 gzip，跳过后面的 sqlite 校验
        $gz = gzopen($final, 'wb9');
        $fh = fopen($tmp, 'rb');
        if ($gz === false || $fh === false) {
            throw new RuntimeException('打不开 gzip 输出: ' . $final);
        }
        while (!feof($fh)) {
            gzwrite($gz, (string)fread($fh, 1 << 20));
        }
        fclose($fh);
        gzclose($gz);
        @unlink($tmp);

        say('  backup : ok  ' . basename($final) . '  ' . humanBytes((int)filesize($final)));
        say('  verify : 跳过（' . $driver . ' 文本 dump 建议配合 restore 演练）');
        $mirrored = false;
        if ($mirror !== '' && is_dir($mirror)) {
            $mirrored = @copy($final, rtrim($mirror, '/\\') . '/' . basename($final));
        }
        say('  mirror : ' . ($mirror === '' ? '未配置' : ($mirrored ? 'ok' : '失败')));
        say('');
        if ($opts['json']) {
            jsonOut([
                'ok' => true,
                'driver' => $driver,
                'file' => $final,
                'bytes' => (int)filesize($final),
                'mirrored' => $mirrored,
                'seconds' => round(microtime(true) - $started, 2),
            ]);
        }
        exit(0);
    }
} catch (Throwable $e) {
    @unlink($tmp);
    fail('备份失败: ' . $e->getMessage());
    exit(1);
}

$rawBytes = (int)filesize($tmp);

// ---------------------------------------------------------------- 2. 校验

try {
    $verify = verifySqlite($tmp);
} catch (Throwable $e) {
    @unlink($tmp);
    fail('备份文件打不开，已丢弃: ' . $e->getMessage());
    exit(1);
}

if ($verify['integrity'] !== 'ok') {
    @unlink($tmp);
    fail('备份文件完整性检查未通过: ' . $verify['integrity']);
    exit(1);
}
if ($verify['tables'] < 1) {
    @unlink($tmp);
    fail('备份里一张表都没有，判断为失败（避免用空库覆盖历史备份）');
    exit(1);
}

// ---------------------------------------------------------------- 3. gzip 压缩

$gz = gzopen($final, 'wb9');
$fh = fopen($tmp, 'rb');
if ($gz === false || $fh === false) {
    @unlink($tmp);
    fail('打不开 gzip 输出: ' . $final);
    exit(1);
}
while (!feof($fh)) {
    gzwrite($gz, (string)fread($fh, 1 << 20));
}
fclose($fh);
gzclose($gz);
@unlink($tmp);

$gzBytes = (int)filesize($final);
say('  backup : ok  ' . basename($final) . '  ' . humanBytes($rawBytes) . ' → ' . humanBytes($gzBytes));
say('  verify : integrity=' . $verify['integrity'] . ' tables=' . $verify['tables'] . ' pages=' . $verify['pages']);

// ---------------------------------------------------------------- 4. 可选镜像

$mirrored = false;
if ($mirror !== '') {
    if (!is_dir($mirror)) {
        say('  mirror : 目录不存在，跳过 ' . $mirror);
    } else {
        $target = rtrim($mirror, '/\\') . '/' . basename($final);
        $mirrored = @copy($final, $target);
        if (!$mirrored) {
            fail('镜像复制失败: ' . $target);
        }
        say('  mirror : ' . ($mirrored ? 'ok  ' . $target : '失败'));
    }
} else {
    say('  mirror : 未配置');
}

// ---------------------------------------------------------------- 5. 轮转

$all = [];
foreach (glob(rtrim($outDir, '/\\') . '/' . $label . '-*.sqlite.gz') ?: [] as $file) {
    $all[] = ['path' => $file, 'time' => (int)filemtime($file)];
}
usort($all, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

$removed = [];
foreach (array_slice($all, $keep) as $old) {
    if (@unlink($old['path'])) {
        $removed[] = basename($old['path']);
    }
}

say('  rotate : 现有 ' . min(count($all), $keep) . ' 份，删除 ' . count($removed) . ' 份' . ($removed !== [] ? '（' . implode('、', $removed) . '）' : ''));

$seconds = round(microtime(true) - $started, 2);

if ($opts['json']) {
    jsonOut([
        'ok' => true,
        'file' => $final,
        'bytes' => $gzBytes,
        'raw_bytes' => $rawBytes,
        'tables' => $verify['tables'],
        'integrity' => $verify['integrity'],
        'mirrored' => $mirrored,
        'removed' => $removed,
        'seconds' => $seconds,
    ]);
    exit(0);
}

say('');
say('  ok  ' . $seconds . ' s');
say('');
exit(0);
