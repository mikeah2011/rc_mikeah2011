<a id="directory"></a>
# 仓库目录补充说明

此文件对 README.md 列出的目录结构做逐项补充，帮助开发者快速定位实现点与职责边界。

app/
- Console/Commands/FlushOutbox.php — artisan 命令：outbox:flush，用于把 outbox 条目转成队列任务。
- Http/Controllers/NotificationController.php — API 入口：store(), retry()，委托 Service；调用方鉴权仍待实现。
- Services/NotificationService.php、NotificationDispatcher.php — 创建/重投事务、共用调度与队列配置约束。
- Jobs/DeliverNotification.php — 共用投递、轮次隔离、并发锁、队列重试与失败处理。
- Jobs/DeliverTargetNotification.php — Outbox 使用的旧类名兼容入口；目前仅支持单 HTTP 目标。
- Channels/ — ChannelContract, ChannelManager, HttpChannel：HTTP 驱动及其抽象，不表示其他渠道已实现。
- Models/Notification.php, NotificationAttempt.php, NotificationTarget.php — 业务模型，与 Repository 交互。
- Models/Outbox.php — Outbox 模型（轻量化，记录待转发消息）。

config/
- notifications.php — 所有与投递、重试、outbox 相关的可配置项（详见文件）。

database/migrations/
- 包含 notifications、notification_attempts、notification_targets、outbox 等迁移文件。请按时间顺序阅读文件名。

documents/
- 集中存放 SA/SD、操作指南、实现计划与 OpenAPI 草案；所有面向运维/产品/架构的说明放在此处。

tests/
- Feature/ 包含关键流程的集成/功能测试（DeliveryReliabilityTest、CreateNotificationTest、RetryNotificationTest）。

部署与运维要点
- Scheduler：`routes/console.php` 每分钟调度 `outbox:flush`，运行 `php artisan schedule:work`。
- 队列：默认 database queue；本服务拒绝 sync 等非持久异步配置。生产队列切换须保留一致性边界。
- 容器：Compose 包含 API、Worker、Scheduler 和数据库，初始化顺序见 [启动说明](SETUP.md)。

联系方式
- 维护者：mikeah2011
