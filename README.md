# API 通知系统（Notifications MVP）

最小可行实现（MVP）实现目标：接收业务系统提交的通知请求（URL、Method、Headers、Body），持久化并异步投递到目标 HTTP(S) 端点，提供尝试记录与人工重投能力。MVP 在本项目语境中表示“最小可行实现（Minimum Viable Implementation）”，不是系统名称。

## 主要功能（MVP）
- 接收并验证通知请求；支持幂等键以防重复创建。
- 持久化通知记录与投递尝试记录（notification_attempts）。
- 使用 database queue 异步投递；实现指数退避、Retry-After 支持与最大重试次数。
- 提供人工重投接口：POST /api/notifications/{id}/retry。
- 配置驱动（config/notifications.php），并包含开发环境（SQLite）与容器化（docker-compose）说明。

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
- [CHANGELOG](documents/CHANGELOG.md) — 仓库专用的变更记录与策略调整文档.
- [TECH_STACK](documents/TECH_STACK.md) — 技术栈选型记录与决策历史.
- [PLAN](documents/PLAN.md) — 实施计划与分批规则.
- [AI_MODEL_POLICY](documents/AI_MODEL_POLICY.md) — AI 模型调用与权限放开策略.
- [SETUP](documents/SETUP.md) — 本地与容器化启动说明.
- [AI Coding 作业](documents/AI_Coding_作业.pdf) — 课程/作业说明文档.

## 目录结构（概要）
- app/ — Laravel 应用代码（Controllers, Models, Jobs, Providers）。
- config/ — 配置文件（包括 config/notifications.php 用于投递/重试策略）。
- database/migrations/ — 数据库迁移文件（notifications, notification_attempts, jobs 等）。
- app/Jobs/ — 异步投递逻辑（DeliverNotification Job 与重试实现）。
- routes/ — API 路由（routes/api.php 提供创建与人工重投接口）。
- tests/Feature/ — Feature 测试示例（创建与重投流程）。
- documents/ — 项目文档目录（PLAN.md, CHANGELOG.md, TECH_STACK.md, SETUP.md 等）。
- docker-compose.yml, docker/ — 容器化启动文件，用于在有 Docker 的机器上做端到端验证。

## 主要功能点（更细化）
- 接收层（API）
  - POST /api/notifications：接收通知请求并持久化入库，支持幂等键。
  - POST /api/notifications/{id}/retry：对失败的通知发起人工重投并增加 delivery_round。
- 数据与幂等性
  - notifications 表记录目标地址、payload、headers、状态与 delivery_round。
  - 使用 client_id + idempotency_key 防止重复创建。
- 投递与重试
  - DeliverNotification Job 负责 HTTP 投递、记录 NotificationAttempt 及判定重试逻辑。
  - 支持 Retry-After（秒或 HTTP-date）、指数退避+抖动、可配置的 max attempts。
- 可观察性与回放
  - 保存每次尝试的响应代码与 body（notification_attempts），便于人工诊断与重投。
- 配置驱动
  - config/notifications.php 提供退避参数、最大重试次数与可调整的投递策略。

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

