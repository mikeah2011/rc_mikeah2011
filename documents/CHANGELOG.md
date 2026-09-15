<a id="changelog"></a>
# 变更记录与策略调整（项目专用文档）

此文件作为仓库中专有的变更记录与策略调整文档（替代不应长期承载变更历史的 README）。

---

## 概要

本仓库为“API 通知系统（Notifications MVP）”的实现，采用 Laravel + PostgreSQL（本地可用 SQLite 验证）。为了保证可审计与分批交付，项目将在此记录每个批次的变更摘要、重要提交、以及策略/权限调整决策。

> 说明：MVP 在本项目语境中表示“最小可行实现（Minimum Viable Implementation）”，不是系统名称。

## [0.2.0] - 2026-09-15

### Added
- 增加 Outbox 到持久化 database queue 的发布闭环，并通过 Laravel Scheduler 定时运行 `outbox:flush`。
- 增加按投递轮次、状态和共享锁进行的队列投递保护。
- 增加 HTTP 通知可靠性回归测试，覆盖入队失败、事务回滚、重试、`Retry-After`、原始 Body、重复任务和人工重投。
- 增加面向需求评审的 SA/SD、启动说明、OpenAPI 草案和运行边界说明。

### Changed
- 默认运行模式明确为 Outbox + database queue，不再把 Redis/Horizon 作为第一版必需组件。
- 统一创建通知和人工重投的投递调度逻辑，并携带 `delivery_round` 防止旧任务覆盖新轮次。
- 保留原始字符串 Body，HTTP 客户端不自动跟随重定向；临时错误采用有限退避重试，永久错误进入 `failed`。
- 将 Compose 拆分为 API、Worker、Scheduler 和 PostgreSQL 开发服务，并补充初始化顺序。

### Fixed
- 修复先标记 Outbox 已处理、后异步发布可能造成的漏投窗口。
- 修复临时 HTTP 失败只保存 `next_attempt_at` 却没有重新释放队列任务的问题。
- 修复旧 Job 吞掉异常、不同幂等请求内容未返回冲突，以及无效 Outbox 记录阻塞有效记录的问题。

### Known limitations
- 当前语义是允许重复的有限重试，不保证外部业务恰好执行一次。
- 调用方鉴权、完整 SSRF/出口限制、监控对账、独立 DLQ 和真实生产 Broker 演练尚未完成。
- 当前只支持单请求、单 HTTP 目标；非空 `target_id` 不会被静默当作父通知目标投递。

---

## 变更记录（按阶段/批次摘要）

### 初始文档与策略（批次 0）
- 新增文件：`documents/plan.md`（实现计划、分批规则、验收标准）。
- 新增文件：`documents/AI_MODEL_POLICY.md`（模型调用策略与权限放开流程）。
- 新增文件：`documents/SETUP.md`（本地/容器化开发环境搭建说明）。
- 目的：把决策与审批流程写入仓库，确保后续自动化或代理化操作按规则执行。

### 导入 Laravel 骨架元数据（批次 1）
- 导入核心 scaffold（`composer.json`、`artisan`、`bootstrap`、`config` 等最小元数据）。
- 提交示例：12e121b、a50fcd6、018bd66（代表性提交）。

### 最小 API 路由（批次 2）
- 添加 `routes/api.php`：POST /api/notifications（返回 202 + UUID）。
- 后来 refactor 为使用 Controller（提交：35771b4）。

### DB 表、模型与 Job stub（批次 3）
- 新增迁移：`notifications`, `notification_attempts`，以及模型 `Notification`, `NotificationAttempt`。
- 新增 Job stub `DeliverNotification`。
- 提交示例：f2cc042。

### 持久化入队（批次 4）
- Controller 在事务内持久化并 dispatch Job（确保原子性）。
- 添加 `jobs`/`failed_jobs` 迁移以支持 database queue。
- 提交示例：5047225、d11b011。

### 投递器实现与重试（批次 5）
- 实现 `DeliverNotification`：HTTP 投递、记录 Attempt、指数退避、支持 `Retry-After`、最大重试次数配置。
- 配置：`config/notifications.php`。
- 提交示例：8d13881、a37671e、c4810c0。

### 人工重投与测试（当前批次）
- 新增 `POST /api/notifications/{id}/retry`，支持 delivery_round++，重置 attempts 并重新入队。
- 添加 Feature tests（CreateNotificationTest, RetryNotificationTest）。
- 在本地用 SQLite 运行迁移并通过 Feature tests（3/3）。
- 提交示例：5952348、2f870b8、d19a82a。

---

## 策略调整记录（AI 模型 / 权限 / 自动化策略）

1. 模型选择与角色分离
   - 讨论阶段（规划/设计）采用更“智能/保守”的模型进行系统规划与变更建议。
   - 实施阶段可逐步切换到自动模式（Auto），但需按步骤放开权限并记录每次放开决策。详见 `documents/AI_MODEL_POLICY.md`。

2. 分批交付规则（必须在 `plan.md` 中记录并遵守）
   - 每次提交应保持“小批次、可回滚、可验证”的原则。
   - 任何会引起运行时、数据库结构或对外接口变更的提交都必须包含迁移、回退思路、以及对应的测试。

3. 权限与自动化放开流程
   - 初期：AI 仅能创建/修改非破坏性文档与代码草案；任何生产/远端推送需人工批准。
   - 授权提升：当计划和实现验证完成后，逐步授予自动化更多权限（例如：CI 触发、容器启动），每一步都记录在 `AI_MODEL_POLICY.md`。

---

## 恢复/审计指南

- 如果你怀疑 README 中原有变更记录被覆盖，请在仓库中搜索 `documents/plan.md`, `documents/AI_MODEL_POLICY.md`, `documents/SETUP.md` 以获取详细历史。
- 本文档为当前 authoritative 的变更/策略摘要；更详细的 per-batch 变更（含提交 ID 与日期）请参见 Git 历史。

---

## 待办（建议）
1. 把每个批次的变更摘要追加到本文件（或迁移为 `CHANGELOG/` 目录下的分文件），以便长期审计。
2. 在 CI 中加入自动化检查，确保每次迁移都能在 sqlite 或测试 DB 上运行并通过测试套件。
3. 完成容器化端到端验证后，把成功记录写入本文件（包括运行命令与环境信息）。

---

文件位置：`/documents/CHANGELOG.md`（本文件）
相关文件：`plan.md`, `README.md`, `AI_MODEL_POLICY.md`, `SETUP.md`

最后更新：2026-09-15 11:31:20 +08:00
