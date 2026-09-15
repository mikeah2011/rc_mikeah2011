<a id="ai_usage"></a>
# AI 使用说明（AI_USAGE）

## 目的
记录项目中 AI 所参与的工作类别、采纳决策、以及每次自动化/权限放开的证明与审计要点，供项目负责人复核。

## AI 参与范围（当前仓库实践）
- 草拟文档（PLAN.md、SETUP.md、TECH_STACK.md 等）。
- 生成 Laravel scaffold 与初始代码（Controller、Job stub、迁移草稿）。
- 实现初版投递器逻辑、测试用例与本地 SQLite 验证流程。
- 协助组织文档目录与 README 结构。

## 已采纳的 AI 建议
- 使用 database queue 以保证“持久化+入队原子性”。
- 投递语义：2xx 成功，408/429/5xx 重试，其他 4xx 永久失败。
- 支持 Retry-After、指数退避与抖动。
- 将项目文档放入 documents/ 并在 README 中建立索引与目录树。

## 未采纳或需人工决定的建议
- SSRF 白名单具体规则由安全负责人制定。
- 生产级 log/metrics 的具体后端（Prometheus/Grafana/Datadog）由团队选定。

## 审计记录（示例格式）
每次 AI 执行或自动提交应包含：
- model: gpt-5-mini
- permissions: write-local-repo
- approver: @michael
- summary: "添加 DeliverNotification Job，实现基础重试逻辑并通过 3 个 Feature tests"

## 变更记录采集位置
- 模型/权限/审批记录请同时提交到 documents/AI_MODEL_POLICY.md 与本次文件的变更历史段（或在 commit message 中包含模板）。

## 如何请求 AI 执行改动
- 在 issue/PR 中列出明确的“单一变更目标”（例如：新增 migration、实现一个 API 路径），并在描述中附上是否允许远程推送与所需权限。
- 对于危险操作（生产部署、外网凭据使用），必须在 issue 中获得明确批准并在变更中引用批准人。

---

最后更新：2026-09-15
