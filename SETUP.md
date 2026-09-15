第一批：项目骨架与本地启动说明

目标

- 将 Laravel 项目骨架和依赖声明落入仓库（不包含 vendor/）。
- 提供 Postgres + database queue 的最小本地启动说明。
- 遵循“分批提交”规则：每批只包含一个清晰目标，不一次性提交全部功能。

先决条件

- PHP 8.3+ 和 Composer 已安装（本地环境有 PHP 8.5 & Composer）。
- PostgreSQL 可用或使用 SQLite 做开发验证（生产建议使用 PostgreSQL）。

快速启动（开发）

1. 复制环境文件并生成应用密钥：

   cp .env.example .env
   composer install
   php artisan key:generate

2. 使用 SQLite（快速验证，无需 PostgreSQL）：

   # 在 .env 中将 DB_CONNECTION=sqlite 并创建文件
   touch database/database.sqlite
   php artisan migrate
   php artisan serve

3. 使用 PostgreSQL（推荐）：

   # 确保 PostgreSQL 运行并创建数据库
   psql -c "CREATE DATABASE notifications_dev;"
   # 更新 .env 中的 DB_* 值
   composer install
   php artisan migrate
   php artisan serve

注意

- 不要提交 .env 或 vendor/ 到仓库。
- 这一批不会包含通知 API 路径、migrations、或投递器代码；这些将在后续小批次中逐步添加并提交。
