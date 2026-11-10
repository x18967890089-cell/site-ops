# site-ops

自己维护一个小网站（PHP + SQLite / MySQL，一台 VPS 或 NAS 上的容器）时，真正会出事的从来不是写页面，而是这些：

- 数据库悄悄坏了没人知道，直到某天页面全白；
- 备份「以为有」，真要用时发现是空的、或者三天前的；
- 上传目录里有记录、文件没了，页面一片裂图；
- 改完链接有 404，自己没点过那一页；
- 站点挂了，第二天才从别人嘴里知道。

这个仓库就四件工具，一条命令跑完一件事，全部**零框架依赖**：不 import 你的 `App`、不假设你的表结构，用一份 JSON 描述「你的站长什么样」。

| 脚本 | 干什么 | 一条命令 |
|---|---|---|
| `bin/health-check.php` | 数据库完整性、表行数、上传孤儿、备份新鲜度、扩展/磁盘/标志位 | `php bin/health-check.php --config=site-ops.json` |
| `bin/backup-now.php` | 在线一致性快照（SQLite `VACUUM INTO`）+ gzip + 校验 + 轮转 + 可选镜像 | `php bin/backup-now.php --config=site-ops.json` |
| `bin/check-links.php` | 从入口页爬同源链接，报 4xx/5xx | `php bin/check-links.php --start=http://127.0.0.1:8080/` |
| `bin/notify-check.ps1` | 探活 + 只在失败时推 webhook（Windows 计划任务用） | `.\bin\notify-check.ps1 -Url https://example.com/health` |

## 真实输出

`health-check.php`（故意造了一个「库里有、磁盘上没有」的上传文件）：

```
========================================
 fixture site 健康自检  2026-11-10 17:56:11
========================================

【数据库】
  文件                     /srv/site/data/app.db
  大小                     12.0 KB
  ✅ 完整性                  ok
  页面 / 空闲页         3 / 0

【数据量】
  photos                     2
  posts                      3
  合计                     2 张表 / 5 行

【上传文件】
  ❌ 缺失                     2 个
  目录内文件数         2

【备份】
  ❌ 份数                     0

【运行环境】
  PHP                        8.3.36 / cli
  ✅ 扩展 pdo_sqlite          ok
  时区                     Asia/Shanghai（现在 17:56）
  磁盘可用               179.1 GB

========================================
 ❌ 需要处理（2 项）：
    1. 有 2 个文件在库里被引用但磁盘上没有：gone.jpg、gone_t.jpg
    2. 备份目录是空的：/srv/site/backups
========================================
```

`backup-now.php`：

```
== backup-now ==
  source : /srv/site/data/app.db  (sqlite)
  out    : /srv/site/backups
  keep   : 3 份

  backup : ok  app-20261110-175611.sqlite.gz  12.0 KB → 321.0 B
  verify : integrity=ok tables=2 pages=3
  mirror : 未配置
  rotate : 现有 1 份，删除 0 份

  ok  0.02 s
```

`check-links.php`：

```
检查 3 个链接（入口 http://127.0.0.1:8123/，深度 1）

  ✅ 200  http://127.0.0.1:8123/                                         2 ms
  ✅ 200  http://127.0.0.1:8123/ok.php                                   1 ms
  ❌ 404  http://127.0.0.1:8123/missing.php                              0 ms

结果: 1 个死链
  · http://127.0.0.1:8123/missing.php → HTTP 404
```

## 快速开始

要求：PHP 8.1+（CLI）、`pdo_sqlite` 或 `pdo_mysql`；`notify-check.ps1` 需要 Windows PowerShell 5.1+。

```bash
git clone https://github.com/x18967890089-cell/site-ops.git
cd site-ops
cp site-ops.example.json site-ops.json     # 改里面的路径
php bin/health-check.php --config=site-ops.json
php bin/backup-now.php  --config=site-ops.json
```

### 配置

两个脚本不传 `--config` 时默认读**当前目录的** `site-ops.json`；显式传了 `--config=...` 却找不到文件会直接退 2，而默认文件不存在时 `backup-now.php` 仍可用 `--db=` 纯命令行跑。

`site-ops.json` 里所有相对路径都**相对配置文件所在目录**（不是相对当前工作目录）——这点是刻意的：从 cron、从别的目录、从 Windows 计划任务调用，行为都一样。

```json
{
  "name": "我的小站",
  "root": ".",
  "db": { "driver": "sqlite", "path": "data/app.db" },
  "tables": ["posts", "photos"],
  "uploads": { "dir": "uploads", "table": "photos", "columns": ["filename", "thumb"] },
  "backup": { "dir": "backups", "keep": 14, "mirror": "/mnt/onedrive/site-backups", "max_age_hours": 48 },
  "require_extensions": ["pdo_sqlite", "mbstring"],
  "flags": { "APP_DEBUG": "0" }
}
```

| 键 | 作用 |
|---|---|
| `db.driver` | `sqlite` / `mysql` / `pgsql` |
| `db.path` | sqlite 文件路径 |
| `db.dsn` / `db.user` / `db.pass` | MySQL/PostgreSQL 用；口令只从配置读，不进命令行 |
| `tables` | 只统计这些表（不写就全统计） |
| `uploads.dir` | 上传目录；配 `table` + `columns` 就能查孤儿文件 |
| `backup.dir` / `keep` / `mirror` / `max_age_hours` | 备份目录、保留份数、二次镜像目录、新鲜度阈值 |
| `require_extensions` | 缺了就直接红 |
| `flags` | 环境变量断言（例如上线前 `APP_DEBUG` 必须是 `0`） |

## 为什么这么写

四个决定，都是踩出来的：

**SQLite 备份用 `VACUUM INTO`，不 `copy()`。** 正在写的 SQLite 文件直接拷，很容易拿到半页数据；`VACUUM INTO` 在一个读事务里把整库写成一个新文件，天然一致，而且顺带整理了空闲页。

**备份必须自己验一遍再算成功。** 脚本会打开刚写好的备份，跑 `PRAGMA integrity_check`、数表数量；表数为 0 直接判失败并丢弃——避免「空库」把历史备份轮转掉。这是最容易犯的错：备份脚本安静地成功，直到恢复那天才发现全是空文件。

**MySQL 口令走环境变量（`MYSQL_PWD` / `PGPASSWORD`），不进命令行。** 命令行参数在 `ps`/任务管理器里是明文。

**链接检查默认不请求外链。** 只跟同源（scheme + host + port）链接，免得把别人的站当压力测试目标，也免得自己变成爬虫。

## 定时跑

Linux（cron）：

```cron
# 每天 03:20 备份，保留 14 份
20 3 * * * cd /srv/site && php /opt/site-ops/bin/backup-now.php --config=site-ops.json >> /var/log/site-backup.log 2>&1

# 每小时体检，出问题才发信（用 notify-check 也行）
5 * * * * cd /srv/site && php /opt/site-ops/bin/health-check.php --config=site-ops.json --json >> /var/log/site-health.log 2>&1
```

Windows（计划任务）：

```powershell
schtasks /create /tn "site backup" /sc daily /st 03:20 /tr "powershell -NoProfile -ExecutionPolicy Bypass -File C:\site-ops\bin\notify-check.ps1 -Url https://example.com/ -Webhook https://ntfy.sh/my-topic -Quiet"
```

> `notify-check.ps1` 只在**失败**时输出并发通知，成功时安静——这样每五分钟跑一次也不会烦你。

## 退出码

四个脚本都按同一套语义返回，方便直接接进 CI 或计划任务：

| 码 | 含义 |
|---|---|
| `0` | 正常 |
| `1` | 检查不通过（有问题要处理） |
| `2` | 参数或配置错误（不是站点问题） |

`--json` 时结果也走同一套码，`ok:false` 对应 `1`。

## 已知限制

- 四脚本只在 Linux（CI）与本机 Windows 上跑过；macOS 没验过（`check-links.php` 走 curl，理论上没问题）。
- `health-check.php` 的 MySQL/PostgreSQL 分支只做了「连不上就报错」的骨架，行数统计用 `SHOW TABLES`，没在真实业务库上跑过。

- 只做「检查」，不自动修：孤儿文件、VACUUM、删旧备份之外的写操作一律不碰。
- MySQL/PostgreSQL 的备份不做恢复演练（只保证 dump 命令返回 0 且文件非空），恢复请自己跑一次 `mysql < dump.sql`。
- `check-links.php` 不解析 JS 动态生成的链接，也不做表单提交；SPA 站点只能查到初始 HTML 里的链接。
- 没有 curl 扩展时状态码会退化成「能读到就算通」，判断精度下降。

## 许可证

MIT © 2026 Reid — 见 [LICENSE](LICENSE)。
