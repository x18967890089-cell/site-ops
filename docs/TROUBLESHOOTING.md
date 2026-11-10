# 故障排查

按「你看到的报错」查，不按脚本分节。

## `JSON 解析失败` / `exit 2`

脚本读不到配置，或者配置不是合法 JSON。

- 路径是相对**当前工作目录**解析的（`--config=site-ops.json` 指的是你 shell 所在目录）。想固定就用绝对路径。
- Windows 记事本存 UTF-8 会在开头加 BOM，`json_decode` 直接失败。用 `php -r "var_dump(json_decode(file_get_contents('site-ops.json')) !== null);"` 验一下，`false` 就是这个原因。
- 结尾多一个逗号也是非法 JSON（JSON 不允许尾逗号，跟 PHP 数组不同）。

## `配置里没有 db.driver`

`db` 段必须显式写 `driver`。只写 `path` 不写 `driver` 时脚本不去猜——猜错去连 MySQL 比报错更难查。

## `sqlite 打不开` / `SQLSTATE[HY000] [14] unable to open database file`

三种常见原因，按顺序排：

1. **路径错**：`db.path` 是相对配置文件的。用 `--json` 看 `notes` 里打印的绝对路径。
2. **目录没写权限**：PHP-FPM 跑在 `www-data`，CLI 跑在你自己的账号下。`ls -ld data/ && id` 对一下。SQLite 不只是读文件，还要在**同目录**建 `-journal`/`-wal`。
3. **WAL 模式 + 网络盘**：SQLite 的 WAL 需要共享内存，放在 SMB/NFS 挂载上会随机报 `disk I/O error`。数据库放本地盘，备份再往网络盘镜像。

## 健康检查报「有 N 个文件在库里被引用但磁盘上没有」

这是孤儿文件的**反向**：库里有记录、磁盘没文件（页面会裂图）。脚本只报不删，因为删记录是不可逆动作。

修法：先确认这些文件是不是被清理脚本误删，再从备份里捞，或者把对应记录改成占位图。真要批量清理，自己写个脚本先 `SELECT` 出来看一眼再 `UPDATE`。

## 健康检查报「备份目录是空的」/「最新备份是 N 小时前的」

说明 `backup-now.php` 没在跑，或跑了但失败退出了。查两处：

- cron 日志：`grep backup-now /var/log/site-backup.log | tail -20`；
- 计划任务历史：`schtasks /query /tn "site backup" /v /fo LIST`。

`max_age_hours` 设成 `48` 意味着「两天没成功备份就红」。如果你的备份是每周一次，把这个值调到 `192`，否则它天天红。

## `备份校验失败：integrity_check` / `表数为 0，丢弃`

**这是脚本在保护你。** 它拒绝把一个空库或坏库当成有效备份留下来，否则轮转逻辑会把仅有的好备份删掉。

出现这个报错通常是：

- 应用正在迁移/重建表，`VACUUM INTO` 拿到的是半成品结构 → 迁移期间跳过备份；
- 磁盘满了，快照文件被截断 → `df -h` 看一眼，再看 `backups/` 里有没有 `*.partial`；
- SQLite 文件本身已损坏 → 先 `sqlite3 app.db "PRAGMA integrity_check;"` 手工确认。

## `mysqldump: command not found` / `pg_dump: command not found`

脚本调的是系统命令，不打包二进制。装客户端：

```bash
apt-get install -y default-mysql-client     # MariaDB/MySQL
apt-get install -y postgresql-client        # PostgreSQL
```

装完 `command -v mysqldump` 要能返回路径——PHP-FPM 的 PATH 往往比你的 shell 短，必要时在配置里用绝对路径自己跑 `exec`。

## MySQL 备份报 `Access denied`，但手工能连

大概率是口令里有特殊字符，被 shell 或 `MYSQL_PWD` 转义吃了。

- 脚本用 `putenv('MYSQL_PWD=...')` 传口令，不走命令行，所以 `!` `$` 这类字符是安全的；
- 若你改过脚本走命令行，`!` 在 bash 交互模式下会触发历史展开，必须单引号包住。

## `check-links.php` 报的 URL 形状奇怪（出现 `/\/`、`//`）

老版本在 Windows 上踩过：`dirname('/')` 返回反斜杠。当前版本已归一化。如果你在改这一段，记住 `dirname()` 是**平台相关**的，路径拼接前一律 `str_replace('\\','/',...)`。

## `check-links.php` 把所有外链都算成死链

默认只检查同源链接（scheme + host + port 全等），跨域链接会**跳过不请求**。要连外链一起查就显式改判断——但注意那会把别人的站拖进来，别对生产环境跑。

## `notify-check.ps1` 说 `ok` 但站点其实是挂的

探活只证明了「这个 URL 返回了 2xx/3xx」。常见假阳性：

- 首页被 CDN 缓存了旧版本，源站已挂；
- 应用返回 200 但内容是错误页（`Fatal error` 也会是 200）。

所以 `-Url` 要指一个真的会碰数据库的路径（比如 `/health.php`），而不是静态首页。

## `notify-check.ps1` 退出码一直是 0

`powershell -File` 传 `-Url a,b` 时**整串会被当成一个参数**，脚本内部得自己 `-split ','`。当前版本已经这么做了；如果你自己包了一层 bat/ps1 调用，注意别把数组再压成字符串传进去。

## `schtasks` 建好了但从不执行

- 用户没登录时任务默认不跑：加 `/ru SYSTEM` 或用「不管用户是否登录都运行」；
- `-File` 路径含空格必须整体加引号，且用绝对路径；
- 策略组里 `-ExecutionPolicy Bypass` 是必须的，不然脚本直接被拦。
