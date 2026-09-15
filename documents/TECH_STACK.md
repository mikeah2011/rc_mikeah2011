# 技术栈选型记录与决策历史

此文档记录项目中关于技术栈选择的讨论、AI 建议与用户偏好之间的权衡，并保存被覆盖或移动过的原始说明与结论，作为审计与追溯依据。

## 结论（当前采纳）
- 采纳：PHP (Laravel) + PostgreSQL（本地可使用 SQLite 进行验证）。
- 原因：复用 Laravel 内置的队列、HTTP 客户端、验证、迁移与测试设施，降低实现复杂度与工程风险；坚持用户对 PHP/Laravel 的偏好，并避免为作业引入不熟悉的新框架。

## 变更历史与来源
- 初始讨论中 Copilot/AI 曾建议 Python/FastAPI（或其他轻量框架），但用户明确偏好 PHP/Laravel。该偏好已被记录并成为最终决策基础（参见 documents/plan.md 与 documents/CHANGELOG.md）。
- 相关提交与文档：
  - 1d3a415 — docs: distinguish system name from minimum viable implementation
  - 5c70991 — docs: add AI model & permission policy
  - 018bd66 — chore(scaffold): import Laravel skeleton files (from temp)
  - c3a1f55 — docs: add CHANGELOG.md and link from README (restore project change & strategy records)

（以上提交 ID 为仓库历史中记录的代表性 commit，详情请查看 git log。）

## 权衡点与理由
- Laravel 的优点：
  - 完整的生态（migrations、queue、jobs、http client、testing）使 MVP 可用更少的额外依赖快速落地；
  - 易于在短周期内实现事务化入队（database queue），满足“持久化 + 入队原子性”的设计要求；
  - 团队/用户熟悉 PHP/Laravel，有助于后续维护与扩展。

- Python/FastAPI 的优点（AI 提出的理由）：
  - 运行时轻量、快速启动、易于编写异步投递逻辑（async HTTP client）；
  - 对部分云原生工具链友好（但这不是当前 MVP 的硬性要求）。

- 取舍理由：
  - 引入新语言/框架会增加实现与交付时间、并增加运维迁移成本；MVP 的目标是尽快、可验证地交付可靠投递能力，故优先选择熟悉且内置支持较多的 Laravel。
  - 若未来业务需要大量并发或更复杂的异步模型，可评估外部队列或专门的异步服务（届时再考虑语言或框架替换）。

## 与 AI 建议的处理规范（摘自 documents/AI_MODEL_POLICY.md / documents/plan.md）
- 记录所有重要 AI 建议并标注“采纳/未采纳/部分采纳”与理由。AI 的替代建议不得自动覆盖用户偏好或项目决策；任何导致架构改变的采纳都需人工批准并在本文件和 CHANGELOG.md 记录。
- 讨论/设计阶段使用更强模型（例如 gpt-6-astra）进行方案生成与权衡；实现阶段优先使用更节约资源的模型，且对自动提交行为实行逐步放开策略。

## 建议的后续动作
1. 将本文件纳入 README 的索引（已同步）。
2. 在 CHANGELOG.md 中按批次追加更详尽的“谁、何时、为何”条目（如果你希望我现在生成，我可以按 commit 历史逐条展开）。
3. 在 AI_USAGE.md 或 AI_USAGE/ 目录下保存一个结构化记录（JSON 或 Markdown），方便追溯每次 AI 的建议、用户决定和实施状态。

最后更新：2026-09-15 11:35:00 +08:00
