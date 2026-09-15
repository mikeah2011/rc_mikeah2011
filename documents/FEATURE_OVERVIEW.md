<a id="feature_overview"></a>
# 功能说明与目录补充（快速手册）

每个目录的补充说明：

- app/Http/Controllers/NotificationController.php
  - store(): 在事务中使用 (client_id, idempotency_key) 确保幂等，写入 notifications 表并 dispatch DeliverNotification Job。
  - retry(): 对 failed 状态允许人工发起新一轮（delivery_round++），并在事务内重置 attempts/next_attempt_at 后入队。

- app/Jobs/DeliverNotification.php
  - 发送 HTTP 请求（不自动跟随 3xx），2xx 视为成功；对 408/429/5xx 判定为短暂错误并按 Retry-After/指数退避重试；其他 4xx 视为永久失败并记录。
  - 每次尝试写入 notification_attempts（status_code、response_body、attempted_at）。

- config/notifications.php（关键配置）
  - max_attempts: 最大尝试次数（例如 5）
  - base_delay / base_backoff_seconds: 基线延迟（秒）
  - backoff_factor: 指数退避因子
  - jitter / backoff_jitter_seconds: 随机抖动范围

- database/migrations
  - notifications 表重要字段示例：id (UUID), client_id, idempotency_key, target_url, method, headers (JSON), body (JSON/text), status (pending/processing/succeeded/failed), delivery_round, next_attempt_at, created_at, updated_at
  - notification_attempts 表记录每次 HTTP 调用的返回码与 body，便于人工诊断与回放。

- documents/
  - 将所有策略、变更、计划、AI 使用记录集中管理，便于审计与教学用途。

定位建议：
- 想查看投递实现：打开 app/Jobs/DeliverNotification.php。
- 想查看入队/幂等策略：查看 app/Http/Controllers/NotificationController.php 与数据库迁移文件。
- 想修改重试参数：调整 config/notifications.php 并重启 worker。

上面结构已在仓库中实现；如需我把此段内容逐步细化（例如列出每个迁移文件名、Controller 方法签名，或把 notifications 表字段完整列出为表格），告诉我需要的格式，我会继续完善并提交。

---

# 主要功能（摘要）

- 接收并验证通知请求；支持幂等键以防重复创建（client_id + idempotency_key）。
- 持久化通知记录与每次投递尝试记录（notifications 与 notification_attempts）。
- 异步投递：默认建议使用 Redis（Laravel 队列 + Horizon）作为 Broker，并可启用 Outbox 模式保证事务性一致性（参见 documents/OUTBOX_BROKER.md）。
- 投递实现支持多渠道（ChannelManager + HttpChannel，可扩展 Email/SMS 驱动），Job 按 target 细粒度投递（DeliverTargetNotification）。
- 重试策略：指数退避、Retry-After 支持、抖动与可配置的最大重试次数（config/notifications.php）。
- 人工重投：提供 POST /api/notifications/{id}/retry，用于对 failed 状态发起新一轮（delivery_round++）。
- 可观察性：保存每次尝试的响应码与响应体，便于诊断与回放。

# 简短结论（快速参考）

- 为何用 Outbox：解决“DB 写入 与 消息发布”原子性/一致性问题，能防止事务提交后消息丢失或双写失败的风险。
- 代价：增加 DB 写入与存储负担，可能导致 outbox 堆积、索引/扫描压力与存储增长。
- 缓解措施：批量写入 targets/outbox（Eloquent::insert 或 raw insert）；给 outbox 建索引并按 available_at 分页查询（limit）；短事务；并发 flush worker 扩容；设置 DLQ 与重试计数；监控 outbox 未处理数、队列深度与 DB I/O；必要时拆库/分区或迁移到专用写库。
- 替代方案：若吞吐极高，考虑 RabbitMQ（publisher-confirm）、Kafka（producer transactions/CDC）或 SQS，将负担从主 DB 转移到消息平台；建议路线为先用 Outbox+Redis（快速部署、保证一致性），再根据负载迁移到更重型平台。
