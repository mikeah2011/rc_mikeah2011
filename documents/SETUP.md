<a id="setup"></a>
# 本地启动与容器化

本说明用于开发和演示，不是生产部署模板。当前默认 Outbox + database queue；API、Scheduler 和 Worker 缺一不可。

## 1. 环境与依赖

使用 PHP 8.4.1+（当前锁定依赖的要求）、Composer，以及所选数据库的 PDO 扩展。Worker 需要 `pcntl` 实施超时；本地可用 SQLite，生产建议 PostgreSQL。不要提交 `.env`、`vendor/` 或真实供应商凭据。

首次初始化（已有 `.env` 时不要覆盖，也不要重复生成正在使用的应用密钥）：

```bash
cp .env.example .env
composer install --no-interaction
php artisan key:generate
```

## 2. 数据库与队列

本地 SQLite：保持 `.env` 中 `DB_CONNECTION=sqlite`，未设置 `DB_DATABASE` 时框架使用 `database/database.sqlite`。

```bash
touch database/database.sqlite
php artisan migrate
```

若使用 PostgreSQL，先创建开发数据库，再配置 `.env`。以下为示例，不要把真实密码写入文档：

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=notifications_dev
DB_USERNAME=your_local_user
DB_PASSWORD=your_local_password
```

默认队列与可靠性配置：

```dotenv
QUEUE_CONNECTION=database
CACHE_STORE=database
NOTIFICATIONS_USE_OUTBOX=true
OUTBOX_FLUSH_LIMIT=100
DB_QUEUE_RETRY_AFTER=90
NOTIFICATIONS_CONNECT_TIMEOUT=5
NOTIFICATIONS_HTTP_TIMEOUT=15
NOTIFICATIONS_JOB_TIMEOUT=30
NOTIFICATIONS_LOCK_SECONDS=60
NOTIFICATIONS_QUEUE_TRIES=100
NOTIFICATIONS_MAX_ATTEMPTS=8
```

通知、Outbox、database queue 默认共用数据库连接。不要用 `sync` 启动可靠异步路径。修改 `.env` 后若有配置缓存，执行 `php artisan config:clear` 并重启 Worker。

## 3. 启动三个进程

分别在三个终端运行：

```bash
# 终端一：HTTP API
php artisan serve
```

```bash
# 终端二：队列消费者
php artisan queue:work database --sleep=1 --tries=100 --timeout=30
```

```bash
# 终端三：每分钟发布到期 Outbox
php artisan schedule:work
```

定时任务位于 `routes/console.php`。可查看注册情况，或不等待下一个分钟刻度，手动发布一批：

```bash
php artisan schedule:list
php artisan outbox:flush --limit=100
```

只启动 API 不会执行 HTTP 投递；只启动 Worker 而不 Flush，也不会消费尚未发布的 Outbox。生产需用进程管理器监管 Worker，并配置 Scheduler；不要同时重复运行多套调度进程而不评估负载。

## 4. 接收与人工重投

将 `https://example.com/webhook` 换成你控制的测试接收端。该示例不会保证 example.com 接受请求。

```bash
curl -i http://127.0.0.1:8000/api/notifications \
  -H 'Content-Type: application/json' \
  -H 'X-Client-Id: demo' \
  -H 'Idempotency-Key: registration-demo-001' \
  --data '{
    "url": "https://example.com/webhook",
    "method": "POST",
    "headers": {"Content-Type": "application/json"},
    "body": "{\"event\":\"registered\",\"user_id\":\"demo-001\"}"
  }'
```

`body` 是字符串，外层 JSON 只负责把它传给本服务；投递时不会再次 JSON 编码。`202` 仅表示已保存。重复提交相同调用方、幂等键和内容返回原通知，不同内容返回 `409`。

通知最终失败后，用实际 ID 替换下方占位符：

```bash
curl -i -X POST \
  http://127.0.0.1:8000/api/notifications/NOTIFICATION_ID/retry
```

重投创建新的投递轮次，仍需经过 Outbox 和 Worker。不要对 `pending` 或 `delivered` 通知发起重投。当前尚无独立的人工重投请求幂等键，操作应由内部受控流程发起。

## 5. 容器化

仓库 Compose 提供 `app`、`worker`、`scheduler`、PostgreSQL 和 Adminer，使用本地开发凭据；不要直接把该配置暴露到公网。

从未初始化的环境开始，先准备配置，启动数据库，再安装依赖和迁移，最后启动消费者，避免任务先于数据库结构运行：

```bash
# 已有 .env 时跳过复制
cp .env.example .env
docker compose build
docker compose up -d db
```

```bash
# 源码目录为 bind mount，需为挂载目录安装 vendor
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan config:clear
docker compose run --rm app php artisan migrate
```

```bash
docker compose up -d app worker scheduler
docker compose ps
docker compose exec app php artisan schedule:list
```

API 位于 `http://localhost:8000`。Compose 给三个应用进程统一注入 PostgreSQL/database queue 环境。Docker 镜像含 `pcntl`；更改 PHP 依赖后需重新构建并更新挂载目录依赖。

```bash
# 手动发布并查看消费者日志
docker compose exec app php artisan outbox:flush --limit=100
docker compose logs --tail=100 worker scheduler
```

## 6. 开发回归与限制

```bash
php artisan test --compact \
  tests/Feature/DeliveryReliabilityTest.php \
  tests/Feature/CreateNotificationTest.php \
  tests/Feature/RetryNotificationTest.php
```

此路径覆盖本地数据库队列与 HTTP fake，不等于真实 PostgreSQL 多进程、真实 Broker 或容器运行验证。目标白名单/SSRF、调用方鉴权、敏感信息保护、监控对账等上线条件见 [SA/SD](SA_SD.md)。
