<a id="changelog"></a>
# 变更记录与策略调整（项目专用文档）

此文件作为仓库中专有的变更记录与策略调整文档（替代不应长期承载变更历史的 README）。

---

## 概要

本仓库为“API 通知系统（Notifications MVP）”的实现，采用 Laravel + PostgreSQL（本地可用 SQLite 验证）。为了保证可审计与分批交付，项目将在此记录每个批次的变更摘要、重要提交、以及策略/权限调整决策。

> 说明：MVP 在本项目语境中表示“最小可行实现（Minimum Viable Implementation）”，不是系统名称。

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
