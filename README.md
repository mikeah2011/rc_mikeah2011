# API 通知系统

最小可行实现（MVP）实现目标：接收业务系统提交的通知请求（URL、Method、Headers、Body），持久化并异步投递到目标 HTTP(S) 端点，提供尝试记录与人工重投能力。MVP 在本项目语境中表示“最小可行实现（Minimum Viable Implementation）”，不是系统名称。

## 目录结构
下面以树状结构展示仓库的主要目录与关键文件，并附上更详尽的说明，便于快速定位实现代码与设计文档：

```
.
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── FlushOutbox.php               # artisan 命令: outbox:flush
│   ├── Http/
│   │   └── Controllers/
│   │       └── NotificationController.php    # API: store() (持久化+入队), retry() (人工重投)
│   ├── Jobs/
│   │   ├── DeliverNotification.php          # 共用投递、重试、轮次隔离
│   │   └── DeliverTargetNotification.php    # Outbox Job 兼容入口（单 HTTP 目标）
│   ├── Channels/
│   │   ├── ChannelContract.php
│   │   ├── ChannelManager.php
│   │   └── HttpChannel.php                  # HTTP 投递实现
│   └── Models/
│       ├── Notification.php                 # 模型: UUID 主键, 状态字段, delivery_round, casts
│       └── Outbox.php                       # Outbox 模型（id, notification_id, target_id, payload...）
├── config/
│   └── notifications.php                     # 可配置参数: use_outbox, max_attempts, backoff, jitter
├── database/
│   └── migrations/
│       ├── *_create_notifications_table.php          # notifications 表 schema
│       ├── *_create_notification_attempts_table.php  # attempts 表 schema
│       ├── *_create_outbox_table.php                 # outbox 表 schema (outbox flushing)
│       └── 0001_01_01_000002_create_jobs_table.php   # jobs/failed_jobs
├── routes/
│   ├── api.php                                   # 接收通知与人工重投
│   └── console.php                               # Outbox 定时调度
├── tests/
│   └── Feature/
│       ├── CreateNotificationTest.php        # 验证入队/幂等/响应码
│       ├── RetryNotificationTest.php         # 验证人工重投与 Outbox
│       └── DeliveryReliabilityTest.php       # 投递闭环与故障恢复
├── documents/
│   ├── PLAN.md                               # 实施计划、分批规则与验收标准
│   ├── OUTBOX_BROKER.md                      # Outbox + Broker 组合方案实现与运维建议
│   ├── SA_SD.md                              # 系统架构与系统设计
│   ├── CHANGELOG.md                          # 按批次记录的变更与策略调整
│   ├── TECH_STACK.md                         # 技术选型、AI 建议与最终决策
│   ├── AI_MODEL_POLICY.md                    # AI 模型调用策略与权限说明
│   └── SETUP.md                              # 本地与 Docker 环境的启动与验证步骤
├── docker-compose.yml                        # Docker Compose（app, db, worker, scheduler）开发示例
├── docker/
│   └── php/Dockerfile                        # PHP 容器镜像定义
├── .env.example                              # 环境变量示例
├── composer.json                             # PHP 依赖清单
└── README.md                                 # 项目总览、快速开始与文档索引
```

## 项目文档

设计说明、功能与运维文档集中在 documents/ 目录：

- 文档索引（入口）： [documents/README.md](documents/README.md)
- 主要功能与快速参考： [documents/FEATURE_OVERVIEW.md](documents/FEATURE_OVERVIEW.md)（或 [documents/FEATURES.md](documents/FEATURES.md)）

（更多设计/运维/实现细节请在 documents/ 内查阅相应文件）



## 快速开始

需要 PHP 8.4.1+ 和 Composer；首次创建 `.env` 并生成密钥，已有环境不要覆盖或重新生成。

1. 复制环境示例并生成应用密钥：

```bash
cp .env.example .env
composer install --no-interaction
php artisan key:generate
```

2. 使用 SQLite（快速验证，无需 PostgreSQL）：

在 `.env` 中使用 SQLite；不设置 `DB_DATABASE` 时默认读取 `database/database.sqlite`：

```dotenv
DB_CONNECTION=sqlite
```

创建数据库并迁移：

```bash
touch database/database.sqlite
php artisan migrate
```

3. 分别在三个终端启动 API、Worker 和 Scheduler：

```bash
php artisan serve
```

```bash
php artisan queue:work database --sleep=1 --tries=100 --timeout=30
```

```bash
php artisan schedule:work
```

注意：
- 默认 Outbox + database queue，无需 Redis/Horizon。Scheduler 每分钟发布待处理任务；也可手动执行 `php artisan outbox:flush --limit=100`。仅启动 API 或 Worker 不会消费尚未发布的 Outbox。
- Worker 需要 `pcntl` 和共享缓存锁。此为内部开发示例；生产使用前须落实 [SA/SD](documents/SA_SD.md) 中的鉴权、安全和运维条件。
- 详细步骤见 [documents/SETUP.md](documents/SETUP.md)。

4. 运行 Feature 测试（示例）：

```bash
php artisan test --testsuite=Feature
```

## 容器化
仓库包含开发用 Docker Compose 和 Dockerfile。首次准备 `.env` 后，依次构建、初始化数据库，再启动应用与消费者：

```bash
docker compose build
docker compose up -d db
```

```bash
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan config:clear
docker compose run --rm app php artisan migrate
```

```bash
docker compose up -d app worker scheduler
```

详细步骤见 [documents/SETUP.md](documents/SETUP.md)。


## 开发规则
- 以小批次提交为原则：每批包含实现、迁移、测试与文档，避免一次性大包提交。
- 运行时变更需包含相应回归用例；数据库结构变化还需包含迁移。
- 敏感操作（生产发布、远程推送）需人工批准，详见 [documents/AI_MODEL_POLICY.md](documents/AI_MODEL_POLICY.md) 与 [documents/PLAN.md](documents/PLAN.md)。

## 联系与贡献
- 维护者：mikeah2011
- 提交规范：请遵循仓库中的分批实现规则；不提交敏感凭据或 vendor 目录。

## 许可证
MIT


## Framework

本项目基于 Laravel (PHP) 实现。如需查看框架文档或深入学习，请访问：https://laravel.com/docs
