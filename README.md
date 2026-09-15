# API 通知系统（Notifications MVP）

最小可行实现（MVP）实现目标：接收业务系统提交的通知请求（URL、Method、Headers、Body），持久化并异步投递到目标 HTTP(S) 端点，提供尝试记录与人工重投能力。MVP 在本项目语境中表示“最小可行实现（Minimum Viable Implementation）”，不是系统名称。

## 目录结构（详细）
下面以树状结构展示仓库的主要目录与关键文件，并附上更详尽的说明，便于快速定位实现代码与设计文档：

```
.
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── NotificationController.php    # API: store() (持久化+入队), retry() (人工重投)
│   ├── Jobs/
│   │   └── DeliverNotification.php           # Job: 执行 HTTP 投递、记录 attempt、决定重试
│   └── Models/
│       └── Notification.php                  # 模型: UUID 主键, 状态字段, delivery_round, casts
├── config/
│   └── notifications.php                     # 可配置参数: base_delay, max_attempts, jitter, backoff_factor
├── database/
│   └── migrations/
│       ├── *_create_notifications_table.php          # notifications 表 schema (target_url, method, headers, body, status)
│       ├── *_create_notification_attempts_table.php  # attempts 表 schema (notification_id, status_code, response_body)
│       └── *_create_jobs_tables_if_needed.php        # queue/failed_jobs (环境可选)
├── routes/
│   └── api.php                               # 路由: POST /api/notifications, POST /api/notifications/{id}/retry
├── tests/
│   └── Feature/
│       ├── CreateNotificationTest.php        # 验证入队/幂等/响应码
│       └── RetryNotificationTest.php         # 验证人工重投逻辑与 dispatch
├── documents/
│   ├── PLAN.md                               # 实施计划、分批规则与验收标准
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

文档索引（快速链接）：
- [SA/SD](documents/SA_SD.md#sa_sd) — 系统架构与系统设计草案（边界、数据模型、失败策略、运维要点）。
- [PLAN](documents/PLAN.md#plan) — 实施计划与分批规则（验收标准、分批边界）。
- [CHANGELOG](documents/CHANGELOG.md#changelog) — 按批次记录的变更与策略调整。
- [TECH_STACK](documents/TECH_STACK.md#tech_stack) — 技术栈选型记录与决策历史。
- [AI_MODEL_POLICY](documents/AI_MODEL_POLICY.md#ai_model_policy) — AI 模型调用与权限放开策略。
- [AI 使用说明](documents/AI_USAGE.md#ai_usage) — 由 AI 参与的工作与审计记录格式。
- [SETUP](documents/SETUP.md#setup) — 本地与容器化启动说明。
- [README 调整策略](documents/README_POLICY.md#readme_policy) — README 维护规则与流程。
- [AI_Coding_Assignment.pdf](documents/AI_Coding_Assignment.pdf) — 课程/作业说明文档。

每个目录的补充说明：
- app/Http/Controllers/NotificationController.php
  - store(): 在事务中使用 (client_id, idempotency_key) 确保幂等，写入 notifications 表并 dispatch DeliverNotification Job。
  - retry(): 对 failed 状态允许人工发起新一轮（delivery_round++），并在事务内重置 attempts/next_attempt_at 后入队。

- app/Jobs/DeliverNotification.php
  - 发送 HTTP 请求（不自动跟随 3xx），2xx 视为成功；对 408/429/5xx 判定为短暂错误并按 Retry-After/指数退避重试；其他 4xx 视为永久失败并记录。
  - 每次尝试写入 notification_attempts（status_code、response_body、attempted_at）。

- config/notifications.php（关键配置）
  - max_attempts: 最大尝试次数（例如 5）
  - base_delay: 基线延迟（秒）
  - backoff_factor: 指数退避因子
  - jitter: 随机抖动范围

- database/migrations/
  - notifications 表重要字段示例：id (UUID), client_id, idempotency_key, target_url, method, headers (JSON), body (JSON/text), status (pending/processing/succeeded/failed), delivery_round, next_attempt_at, created_at, updated_at
  - notification_attempts 表记录每次 HTTP 调用的返回码与 body，便于人工诊断与回放。

- documents/
  - 将所有策略、变更、计划、AI 使用记录集中管理，便于审计与教学用途。

定位建议
- 想查看投递实现：打开 app/Jobs/DeliverNotification.php
- 想查看入队/幂等策略：查看 app/Http/Controllers/NotificationController.php 与数据库迁移文件
- 想修改重试参数：调整 config/notifications.php 并重启 worker

上面结构已在仓库中实现；如需我把此段内容进一步细化（例如列出每个迁移文件名、Controller 方法签名、或把 notifications 表字段完整列出为表格），我可以继续完善并提交更改。

## 主要功能
- 接收并验证通知请求；支持幂等键以防重复创建（client_id + idempotency_key）。
- 持久化通知记录与每次投递尝试记录（notifications 与 notification_attempts）。
- 异步投递：使用 database queue；实现指数退避、Retry-After 支持、抖动与可配置的最大重试次数（config/notifications.php）。
- 人工重投：提供 POST /api/notifications/{id}/retry，用于对 failed 状态发起新一轮（delivery_round++）。
- 可观察性：保存每次尝试的响应码与响应体，便于诊断与回放。 

## 快速开始（开发/验证，SQLite）
1. 复制环境示例并生成应用密钥：
   cp .env.example .env
   php artisan key:generate
2. 切换到 SQLite（可编辑 .env）：
   DB_CONNECTION=sqlite
   DB_DATABASE=database/database.sqlite
   touch database/database.sqlite
3. 安装依赖并运行迁移：
   composer install --no-interaction
   php artisan migrate --force
4. 运行 Feature 测试（示例）：
   php artisan test --testsuite=Feature

## 容器化（可选）
仓库包含 docker-compose.yml 和 Dockerfile，可在有 Docker 环境的机器上用以下命令启动：

  docker compose up -d --build
  docker compose exec app php artisan migrate --force
  docker compose exec app php artisan test --testsuite=Feature

详细步骤见 documents/SETUP.md。

## 文档索引
- [SA/SD](documents/SA_SD.md#sa_sd) — 系统架构与系统设计草案（边界、数据模型、失败策略、运维要点）。
- [PLAN](documents/PLAN.md#plan) — 实施计划与分批规则（验收标准、分批边界）。
- [CHANGELOG](documents/CHANGELOG.md#changelog) — 按批次记录的变更与策略调整。
- [TECH_STACK](documents/TECH_STACK.md#tech_stack) — 技术栈选型记录与决策历史。
- [AI_MODEL_POLICY](documents/AI_MODEL_POLICY.md#ai_model_policy) — AI 模型调用与权限放开策略。
- [AI 使用说明](documents/AI_USAGE.md#ai_usage) — 由 AI 参与的工作与审计记录格式。
- [SETUP](documents/SETUP.md#setup) — 本地与容器化启动说明。
- [README 调整策略](documents/README_POLICY.md#readme_policy) — README 维护规则与流程。
- [AI_Coding_Assignment.pdf](documents/AI_Coding_Assignment.pdf) — 课程/作业说明文档。

## 开发与分批规则（摘要）
- 以小批次提交为原则：每批包含实现、迁移、测试与文档，避免一次性大包提交。
- 所有会影响运行时或 DB 的变更需包含对应迁移与测试。
- 敏感操作（生产发布、远程推送）需人工批准，详见 AI_MODEL_POLICY.md 与 plan.md。

## 联系与贡献
- 维护者：项目作者（本地仓库 rc_mikeah2011）。
- 提交规范：请遵循仓库中的分批实现规则；不提交敏感凭据或 vendor 目录。

## 许可证
MIT


## Framework

本项目基于 Laravel (PHP) 实现。如需查看框架文档或深入学习，请访问：https://laravel.com/docs

