# Outbox + Broker（组合方案）

概述
- 目的：在高吞吐场景下保证“数据库事务写入”与“投递队列调度”的一致性，并把投递异步化以降低 DB 写负载。
- 本仓库实现：默认采用 Redis（Laravel 队列/Horizon）为 Broker，并以 Outbox 模式保证事务性。可后改为 RabbitMQ/Kafka/SQS。

已实现要点
- 数据表：outbox（id, notification_id, target_id, payload, available_at, processed_at, timestamps）。
- 模型：App\Models\Outbox
- Service 修改：NotificationService 在事务内创建通知后，若 config('notifications.use_outbox', true) 为 true，则写入 outbox 行（transaction-safe）。
- Flush 命令：artisan outbox:flush --limit=100 扫描 available 的 outbox 条目，标记 processed_at 并按条 dispatch DeliverTargetNotification（按 target 分发）。
- Job：App\Jobs\DeliverTargetNotification 接收 notification_id 与可选 target_id，委托 ChannelManager 驱动进行实际投递。

如何使用
1. 配置
   - config/notifications.php（新增）
     - use_outbox: true/false
     - outbox_flush_limit: 100
   - 队列驱动：建议在 production 使用 redis + horizon（或 rabbitmq 驱动）。

2. 运行
   - 手动：php artisan outbox:flush --limit=200
   - 定时/自动：在 App\Console\Kernel::schedule() 中加入调度，例如每秒或每分钟运行（依据吞吐），或使用 supervisor/cron 运行常驻 worker。推荐使用短间隔（1s-5s）的小批量 flush。

设计与运维建议
- 批量写 targets：创建通知时批量 insert targets（Eloquent::insert），避免 N 次单行插入。
- 缩短事务：在事务内只写入 notifications/targets/outbox（尽量避免调用外部服务）。事务提交后再由 outbox 转发。
- DLQ（死信队列）：为无法投递的 outbox 条目保留重试计数与最终移动到 dead_letter 表，便于人工排查。
- 监控：队列深度、outbox 未处理行数、failed attempts、平均投递耗时。
- 数据库：生产推荐 PostgreSQL；使用 pgbouncer 或写扩展库减少连接压力；必要时考虑分库或分表。

性能提示
- 使用 Eloquent::insert 做 targets 批量写入；为 outbox 写入使用轻量化 insert（不加载模型）以减小内存/CPU。
- Flush 时按 limit 分页，避免长事务与表扫描。用索引（available_at, processed_at）优化查询。
- 大量入库时可短暂关闭同步索引或分批回填（谨慎）。

后续改进（优先级高→低）
1. 注册并调度 FlushOutbox 命令（Kernel::schedule）并用 supervisor 管控。
2. 增加 dead_letter 表与重试计数/策略（DLQ）。
3. 实现 Channel-specific backoff 与可配置并发（per-channel queue names）。
4. 支持 RabbitMQ/Kafka 驱动与外部托管队列（SQS）切换策略。
5. 性能测试脚本（load test），并按发现优化 DB/队列配置。

参考代码位置
- NotificationService: app/Services/NotificationService.php
- Outbox migration: database/migrations/20260915_120000_create_outbox_table.php
- Outbox model: app/Models/Outbox.php
- Flush command: app/Console/Commands/FlushOutbox.php
- Per-target job: app/Jobs/DeliverTargetNotification.php

备注：已保证测试环境兼容性（在不存在 channel 列的旧测试 DB 中仍能运行）。如需将 outbox 强制写为带 target_id 的单元，请确认通知模型是否先写 targets 并回填 target_id（事务内）。

简短结论（供快速参考）

- 为何用 Outbox：解决「DB 写入 与 消息发布」的原子性/一致性问题，能防止事务提交后消息丢失或消息先发后 DB 写失败的双写风险。
- 代价：把写负担加到 DB，会出现 outbox 堆积、索引/存储和扫描压力。
- 应对积压/丢失/DB 压力的实操措施：批量 insert targets/outbox（Eloquent::insert 或 raw insert）；给 outbox 建索引并按 available_at 分页查询（limit）；短事务；并发 flush worker 扩容；设 DLQ 和重试计数；监控（outbox 未处理数、队列深度、DB I/O）。必要时将 outbox 表拆库/分区或迁移到专用写库。
- 替代方案：RabbitMQ（publisher-confirm）或 Kafka（producer transactions／CDC）能把负担从主 DB 转移到消息平台，适合超大吞吐。建议路线：先用 Outbox+Redis（保证一致性、部署简单）；当吞吐/延迟成为瓶颈，再迁移到 Kafka/CDC 或 RabbitMQ 并把 outbox 作为临时/回退机制。

