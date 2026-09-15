<a id="outbox_broker"></a>
# Outbox 与持久化队列

## 结论

Outbox 是数据库中的事务投递意图，不是 RabbitMQ / Kafka 的替代品。它解决通知记录与“需要投递”之间的一致性；Broker 负责消息的存储和消费调度。两者可以配合，但任何组合都不能单独保证外部 HTTP 副作用恰好发生一次。

**当前默认值是 Outbox + Laravel database queue，不是 Redis/Horizon。** 本仓库不要求安装 Redis、Horizon、RabbitMQ 或 Kafka。生产数据库建议 PostgreSQL；SQLite 用于本地验证。

## 当前实现

1. `NotificationService` 在同一事务保存通知和 Outbox；人工重投也使用相同的 `NotificationDispatcher`，携带新的 `delivery_round`。
2. `outbox:flush` 按 ID 获取有界数量的到期记录，再逐条在事务中重新读取并加行锁。
3. 使用 `Queue::push()` 和 Job 的 `beforeCommit()` 立即入队，成功后才写 `processed_at`，不把发布推迟到数据库提交之后。
4. Job 从数据库读取最新状态，检查轮次、终止状态和下次尝试时间，再投递原始 HTTP 请求。
5. 临时失败由 Job 按持久化的重试时间重新释放到队列，耗尽预算进入 `failed`。新旧 Job 入口共用处理逻辑。

`processed_at` 只表示投递任务已经发布，不代表外部供应商已收到通知。旧 Outbox/Job 没有轮次字段时按第 1 轮处理，不自动绑定到当前轮次。

当前 API 是单请求、单 HTTP 目标。`target_id` 表和兼容 Job 名称仍存在，但非空 target 不在本轮范围内；不能静默使用父通知 URL 冒充目标投递。

## 一致性边界

| 配置 / 故障 | 实际保证 |
|---|---|
| 通知、Outbox、database queue 使用同一数据库连接 | 保存投递意图原子提交；Flush 的入队与标记也可在同一事务提交 |
| Outbox 发布到外部队列或另一数据库连接 | 先发布后标记；发布成功而标记失败可能重复发布，消费者必须容忍重复 |
| 入队报错 | 不标记已处理，错误显式暴露，之后再次 Flush |
| 外部 API 成功但本地提交失败 | 重试可能再次调用 API，须由供应商业务幂等处理副作用 |
| Broker 丢失已确认消息或数据库数据丢失 | 本地 Outbox 不自动发现或弥补这类数据丢失，需要持久性配置、备份及对账方案 |

关闭 Outbox 时，只允许与通知使用**同一数据库连接**的 database queue，事务内入队。`sync / null / deferred / background / failover` 不适用于本服务的可靠异步接收路径，配置错误会明确报错，而不是返回假成功。

当前配置允许 Laravel 已有的 database、redis、sqs、beanstalkd 队列。允许配置不等于已安装驱动、完成真实 Broker 演练或提供持久性保证；RabbitMQ/Kafka 需要额外集成。SQS 可见性时间等服务端参数也须满足超时关系。

## 运行与并发

```bash
# 手动发布一批；不实际执行 HTTP 请求
php artisan outbox:flush --limit=100

# 本地调度器，每分钟运行已注册的 Flush 任务
php artisan schedule:work

# 独立终端运行消费者
php artisan queue:work database --sleep=1 --tries=100 --timeout=30
```

Laravel 13 的定时任务定义在 `routes/console.php`，不要配置一个未被使用的旧 `app/Console/Kernel.php`。每分钟调度意味着首次投递可能多等接近一分钟；有更低延迟需求时，再按实测调整调度频率。

`OUTBOX_FLUSH_LIMIT` 默认 100，`--limit` 可覆盖，范围 1–10000。并发转发器逐行重新检查状态；本轮没有 `SKIP LOCKED` 批量抢占优化。

Worker 通过共享缓存锁与通知行锁串行处理同一通知。当前每次 HTTP 请求期间保持一个通知事务，结果与状态一起提交，避免并发覆盖；代价是 HTTP 等待期间占用数据库连接和行锁。必须使用支持原子锁的共享缓存（默认 database），并让 Worker 的 PHP CLI 支持 `pcntl`。

默认超时关系：连接 5 秒 ≤ HTTP 15 秒 < Job 30 秒 < 缓存锁 60 秒 < 队列 `retry_after` 90 秒。HTTP 预算默认 8 次，队列领取预算默认 100 次；等待锁、提前领取等也可能消耗队列预算。

## 积压、错误和数据库压力

- 先检查 Scheduler / Worker 是否运行及 Flush 错误，再考虑增加并发。格式错误或不支持的 Outbox 记录不能直接标记成功，需人工修正或有审计地隔离。
- 当前不是完整 Outbox DLQ：格式错误记录保留待处理、输出记录 ID，继续发布本批其他有效记录，但命令退出码仍为失败。实际发布/存储异常则直接抛出。若错误记录占满一批，后续记录可能被阻塞，应暂停调度、检查记录并修复或有审计地隔离，不能仅无限重启任务。
- 监控未处理数、最旧待处理时间、队列深度、最终失败量、DB I/O、锁等待和连接数。监控平台及自动对账工具尚未实现。
- 清理历史数据须有明确保留策略，不删除待投递记录；批量写入、索引优化、分区及扩大转发并发必须按压测结果推进，尚不宣称已实现这些优化。
- 不建议关闭关键唯一索引换吞吐，也不能简单把 Outbox 拆到另一数据库而仍声称与业务写入同事务。

## 后续演进

同库 database queue 已可满足第一版的低依赖目标。若队列负载成为主库瓶颈，可评估 Redis/RabbitMQ，但仍需解决双写问题；如果出现事件回放、多消费者或日志级吞吐需求，再评估 Kafka + CDC。Kafka producer transaction 本身不覆盖 Laravel 业务数据库事务，RabbitMQ publisher confirm 也不等于跨系统原子提交。

更完整的取舍、失败语义和上线限制见 [SA/SD](SA_SD.md)，实际启动顺序见 [启动说明](SETUP.md)。
