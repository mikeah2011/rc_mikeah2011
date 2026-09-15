# API 通知系统（Notifications MVP）

最小可行实现（MVP）实现目标：接收业务系统提交的通知请求（URL、Method、Headers、Body），持久化并异步投递到目标 HTTP(S) 端点，提供尝试记录与人工重投能力。MVP 在本项目语境中表示“最小可行实现（Minimum Viable Implementation）”，不是系统名称。

## 目录结构（详细）
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
│   │   ├── DeliverNotification.php          # Job: 旧/兼容投递（delegate to channels）
│   │   └── DeliverTargetNotification.php    # Job: 按 target 投递（Outbox 转发用）
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
│       └── *_create_jobs_tables_if_needed.php        # queue/failed_jobs (环境可选)
├── routes/
│   └── api.php                                   # 路由: POST /api/notifications, POST /api/notifications/{id}/retry
├── tests/
│   └── Feature/
│       ├── CreateNotificationTest.php        # 验证入队/幂等/响应码
│       └── RetryNotificationTest.php         # 验证人工重投逻辑与 dispatch
├── documents/
│   ├── PLAN.md                               # 实施计划、分批规则与验收标准
│   ├── OUTBOX_BROKER.md                      # Outbox + Broker 组合方案实现与运维建议
│   ├── SA_SD.md                              # 系统架构与系统设计
│   ├── CHANGELOG.md                          # 按批次记录的变更与策略调整
│   ├── TECH_STACK.md                         # 技术选型、AI 建议与最终决策
│   ├── AI_MODEL_POLICY.md                    # AI 模型调用策略与权限说明
│   └── SETUP.md                              # 本地与 Docker 环境的启动与验证步骤
├── docker-compose.yml                        # Docker Compose（app, db, queue）示例（在有 Docker 的机器上使用）
├── docker/
│   └── php/Dockerfile                        # PHP 容器镜像定义
├── .env.example                              # 环境变量示例
├── composer.json                             # PHP 依赖清单
└── README.md                                 # 项目总览、快速开始与文档索引
```

## 文档与功能说明（简短指引）

详尽文档请查看 repositories 中的 documents/ 目录：

- 文档索引（入口）： documents/README.md
- 主要功能与快速参考： documents/FEATURE_OVERVIEW.md（或 documents/FEATURES.md）

（更多设计/运维/实现细节请在 documents/ 内查阅相应文件）



1. 复制环境示例并生成应用密钥：

```
cp .env.example .env
composer install --no-interaction
php artisan key:generate
```

2. 使用 SQLite（快速验证，无需 PostgreSQL）：

```
# 编辑 .env：
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
# 创建数据库文件并运行迁移
touch database/database.sqlite
php artisan migrate --force
php artisan serve
```

3. 安装依赖并运行迁移（生产/完整示例）：

```
composer install --no-interaction
php artisan migrate --force
```

注意：
- 默认启用 Outbox（config('notifications.use_outbox') = true）。若启用 Outbox，请定期运行或调度 artisan outbox:flush（示例：php artisan outbox:flush --limit=100）以把 outbox 条目转成队列任务；生产上建议使用 scheduler 或 supervisor 进行短间隔调度。
- 详尽启动步骤、容器化示例与迁移命令请查看 documents/SETUP.md

4. 运行 Feature 测试（示例）：

```
php artisan test --testsuite=Feature
```

## 容器化（可选）
仓库包含 docker-compose.yml 和 Dockerfile，可在有 Docker 环境的机器上用以下命令启动：

  docker compose up -d --build
  docker compose exec app php artisan migrate --force
  docker compose exec app php artisan test --testsuite=Feature

详细步骤见 documents/SETUP.md。


## 开发与分批规则（摘要）
- 以小批次提交为原则：每批包含实现、迁移、测试与文档，避免一次性大包提交。
- 所有会影响运行时或 DB 的变更需包含对应迁移与测试。
- 敏感操作（生产发布、远程推送）需人工批准，详见 AI_MODEL_POLICY.md 与 plan.md。

## 联系与贡献
- 维护者：mikeah2011
- 提交规范：请遵循仓库中的分批实现规则；不提交敏感凭据或 vendor 目录。

## 许可证
MIT


## Framework

本项目基于 Laravel (PHP) 实现。如需查看框架文档或深入学习，请访问：https://laravel.com/docs

