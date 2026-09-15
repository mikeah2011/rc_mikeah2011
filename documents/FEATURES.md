<a id="features"></a>
# 主要功能与开发摘要

项目定位：API 通知系统（Notifications MVP）——以最小可验证范围逐步实现多渠道、事务一致的通知投递平台。

主要功能（详见文档，与 README 摘要区分）
- 接收通知请求并验证（支持 client_id + idempotency_key 幂等）。
- 将通知与每次投递尝试持久化（notifications, notification_attempts）。
- 支持多渠道投递（ChannelManager 抽象，HttpChannel 已实现，后续可扩展 Email/SMS）。
- 异步投递：支持 Outbox 模式 + Broker（默认 Redis/Horizon），并能按 target 拆分精细投递。
- 重试策略：支持 Retry-After、指数退避与抖动，配置化 max_attempts。
- 人工重投 API：对 failed 状态支持人工重投并记录 delivery_round。

开发摘要（最近几批已完成）
- 引入 Repository/Service 分层（Controller → Service → Repository → Model）。
- 统一 ApiResponse 结构并用 JsonResource 封装返回数据。
- 设计并实现 Channel 层（ChannelContract, ChannelManager, HttpChannel）。
- 实现 Outbox 表、Flush 命令与按 target 的队列 Job，确保事务内写入与队列入队一致性。

分批与提交规则
- 每次提交应包含：实现、对应迁移、测试与文档更新。
- 变更需小步、可回退；复杂迁移（跨 DB）需单独 plan 并使用回滚/补丁策略。

下一步建议
1. 把 DeliverNotification 拆成按 target 的 Job（已完成初版 DeliverTargetNotification）。
2. 实现 Email/SMS Channel stub 并注册到 ChannelManager。
3. 增设 DLQ（dead_letter 表）与监控告警仪表盘。
4. 性能验证（load test）并根据结果考虑 Kafka/CDC 路线。

维护者：mikeah2011
