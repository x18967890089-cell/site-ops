<?php

/**
 * check-links.php — 死链巡检：从一个入口页出发，把站内链接全打一遍
 *
 * 只跟同源链接（同 scheme + host + port），外链默认不请求（避免把别人的站拖垮，
 * 也避免被当成爬虫）。相对路径、`//host/path`、`#fragment`、`mailto:` 都会正确处理。
 *
 * 用法：
 *   php bin/check-links.php --start=http://127.0.0.1:8099/
 *   php bin/check-links.php --start=https://example.com/ --depth=2 --max=300
 *   php bin/check-links.php --start=... --json > links.json
 *   php bin/check-links.php --start=... --ignore=/logout|/delete
 *
 * 退出码：0 全部可达 / 1 有死链或请求失败 / 2 参数错误
 */

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function args(array $argv): array
{
    $out = [
        'start' => [],
        'depth' => 1,
        'max' => 200,
        'timeout' => 10,
        'ignore' => [],
        'json' => false,
        'ua' => 'site-ops-link-check/1.0 (+https://github.com)',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $out['json'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDERR, "用法: php bin/check-links.php --start=URL [--depth=N] [--max=N] [--timeout=N] [--ignore=REGEX] [--json]\n");
            exit(0);
        }
        if (preg_match('/^--(start|depth|max|timeout|ignore|ua)=(.*)$/u', $arg, $m) !== 1) {
            fwrite(STDERR, "未知参数: {$arg}\n");
            exit(2);
        }
        $key = $m[1];
        $value = $m[2];
        if ($key === 'start' || $key === 'ignore') {
            $out[$key][] = $value;
            continue;
        }
        if (!ctype_digit($value) || (int)$value < 1) {
            fwrite(STDERR, "--{$key} 必须是正整数\n");
            exit(2);
        }
        $out[$key] = (int)$value;
    }

    if ($out['start'] === []) {
        fwrite(STDERR, "至少要一个 --start=URL\n");
        exit(2);
    }

    return $out;
}

/**
 * 把 href 解析成绝对 URL；不是 http(s) 的返回 null。
 */
function absolutize(string $href, string $base): ?string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#')) {
        return null;
    }
    if (preg_match('/^(mailto:|tel:|javascript:|data:|ftp:)/i', $href) === 1) {
        return null;
    }
    if (str_starts_with($href, '//')) {
        $scheme = (string)parse_url($base, PHP_URL_SCHEME);

        return $scheme . ':' . $href;
    }
    if (preg_match('/^https?:\/\//i', $href) === 1) {
        return $href;
    }

    $parts = parse_url($base);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }

    // dirname('/') 在 Windows 上返回反斜杠（'\'），必须归一化，否则拼出 /\/missing.php
    $dir = isset($parts['path']) ? str_replace('\\', '/', dirname($parts['path'])) : '';
    $dir = rtrim($dir, '/');
    if ($dir === '.' || $dir === '\\') {
        $dir = '';
    }
    $combined = $dir . '/' . $href;

    // 折叠 ./ 与 ../
    $segments = [];
    foreach (explode('/', $combined) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return $origin . '/' . implode('/', $segments);
}

function sameOrigin(string $a, string $b): bool
{
    $pa = parse_url($a);
    $pb = parse_url($b);
    if (!is_array($pa) || !is_array($pb)) {
        return false;
    }

    return ($pa['scheme'] ?? '') === ($pb['scheme'] ?? '')
        && strtolower((string)($pa['host'] ?? '')) === strtolower((string)($pb['host'] ?? ''))
        && (int)($pa['port'] ?? 0) === (int)($pb['port'] ?? 0);
}

/**
 * 去重键：忽略 fragment，保留 query（不同 query 可能是不同页面）。
 */
function urlKey(string $url): string
{
    $hash = strpos($url, '#');
    if ($hash !== false) {
        $url = substr($url, 0, $hash);
    }

    return rtrim($url, '/') === '' ? '/' : $url;
}

/**
 * @return array{code:int, body:string, error:string, ms:int, method:string}
 */
function fetch(string $url, int $timeout, string $ua, string $method = 'GET'): array
{
    $started = microtime(true);

    if (!function_exists('curl_init')) {
        // 没有 curl：只能拿到内容，拿不到状态码，退化成「能读到就算通」
        $body = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => $timeout, 'user_agent' => $ua, 'ignore_errors' => true],
        ]));

        return [
            'code' => $body === false ? 0 : 200,
            'body' => (string)$body,
            'error' => $body === false ? 'file_get_contents failed' : '',
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'method' => 'stream',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => $method === 'HEAD' ? '' : (string)$body,
        'error' => $error,
        'ms' => (int)round((microtime(true) - $started) * 1000),
        'method' => $method,
    ];
}

// ---------------------------------------------------------------- main

$options = args($argv);
$origin = (string)$options['start'][0];

$queue = [];
$seen = [];
$results = [];
$pages = 0;

foreach ($options['start'] as $start) {
    $key = urlKey($start);
    $queue[] = ['url' => $start, 'depth' => 0];
    $seen[$key] = true;
}

$ignorePatterns = [];
foreach ($options['ignore'] as $pattern) {
    $compiled = @preg_match('#' . str_replace('#', '\#', $pattern) . '#', '');
    if ($compiled === false) {
        fwrite(STDERR, "忽略规则不是合法正则: {$pattern}\n");
        exit(2);
    }
    $ignorePatterns[] = $pattern;
}

while ($queue !== [] && count($results) < $options['max']) {
    $item = array_shift($queue);
    $url = (string)$item['url'];
    $depth = (int)$item['depth'];

    foreach ($ignorePatterns as $pattern) {
        if (preg_match('#' . str_replace('#', '\#', $pattern) . '#', $url) === 1) {
            $results[] = ['url' => $url, 'code' => 0, 'ms' => 0, 'ignored' => true, 'depth' => $depth];
            continue 2;
        }
    }

    $response = fetch($url, $options['timeout'], (string)$options['ua'], 'GET');
    $ok = $response['code'] >= 200 && $response['code'] < 400;
    $results[] = [
        'url' => $url,
        'code' => $response['code'],
        'ms' => $response['ms'],
        'error' => $response['error'],
        'depth' => $depth,
    ];
    $pages++;

    if (!$ok || $depth >= $options['depth'] || $response['body'] === '') {
        continue;
    }

    // 只从 HTML 里抽链接
    if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/iu', $response['body'], $matches) === false) {
        continue;
    }
    foreach ($matches[1] as $href) {
        $absolute = absolutize((string)$href, $url);
        if ($absolute === null || !sameOrigin($absolute, $origin)) {
            continue;
        }
        $key = urlKey($absolute);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $queue[] = ['url' => $absolute, 'depth' => $depth + 1];
    }
}

$broken = array_values(array_filter($results, static function (array $row): bool {
    if (($row['ignored'] ?? false) === true) {
        return false;
    }

    return $row['code'] < 200 || $row['code'] >= 400;
}));

if ($options['json']) {
    echo json_encode([
        'ok' => $broken === [],
        'checked' => count($results),
        'broken' => count($broken),
        'results' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($broken === [] ? 0 : 1);
}

printf("\n检查 %d 个链接（入口 %s，深度 %d）\n\n", count($results), $origin, $options['depth']);
foreach ($results as $row) {
    if (($row['ignored'] ?? false) === true) {
        printf("  %-4s %-58s %s\n", 'skip', mb_strimwidth($row['url'], 0, 56, '…'), '被忽略');
        continue;
    }
    $mark = $row['code'] >= 200 && $row['code'] < 400 ? '✅' : '❌';
    printf(
        "  %s %-4s %-58s %5d ms%s\n",
        $mark,
        $row['code'] === 0 ? 'ERR' : (string)$row['code'],
        mb_strimwidth($row['url'], 0, 56, '…'),
        $row['ms'],
        $row['error'] !== '' ? '  ' . mb_strimwidth($row['error'], 0, 40, '…') : ''
    );
}

if ($broken === []) {
    printf("\n结果: 全部可达（%d 个链接）\n\n", count($results));
    exit(0);
}

printf("\n结果: %d 个死链\n", count($broken));
foreach ($broken as $row) {
    printf("  · %s → %s%s\n", $row['url'], $row['code'] === 0 ? '连不上' : 'HTTP ' . $row['code'], $row['error'] !== '' ? '（' . $row['error'] . '）' : '');
}
echo "\n";
exit(1);
