<?php
/**
 * 造一个「有点小毛病」的站点：3 篇文章、2 张照片（其中 2 个缩略图故意不落盘）。
 * 用来演示 health-check 能抓到孤儿文件、以及备份能正常跑通。
 *
 *   php examples/fixture/seed.php
 */
declare(strict_types=1);

$base = __DIR__;
@mkdir($base . '/data', 0775, true);
@mkdir($base . '/uploads', 0775, true);
@mkdir($base . '/backups', 0775, true);

$dbFile = $base . '/data/app.db';
if (is_file($dbFile)) {
    unlink($dbFile);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT)');
$pdo->exec('CREATE TABLE photos (id INTEGER PRIMARY KEY, name TEXT, filename TEXT, thumb TEXT)');

$st = $pdo->prepare('INSERT INTO posts (title, body) VALUES (?, ?)');
foreach ([['第一篇', 'hello'], ['第二篇', 'world'], ['第三篇', 'again']] as $row) {
    $st->execute($row);
}

$st = $pdo->prepare('INSERT INTO photos (name, filename, thumb) VALUES (?, ?, ?)');
$st->execute(['正常照片', 'a.jpg', 'a_t.jpg']);
$st->execute(['丢了的照片', 'gone.jpg', 'gone_t.jpg']);

file_put_contents($base . '/uploads/a.jpg', str_repeat('J', 2048));
file_put_contents($base . '/uploads/a_t.jpg', str_repeat('J', 256));

echo "seed ok: {$dbFile}\n";
echo "  故意缺两个文件: uploads/gone.jpg, uploads/gone_t.jpg\n";
