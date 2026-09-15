<a id="directory"></a>
# 仓库目录补充说明

此文件对 README.md 列出的目录结构做逐项补充，帮助开发者快速定位实现点与职责边界。

app/
- Console/Commands/FlushOutbox.php — artisan 命令：outbox:flush，用于把 outbox 条目转成队列任务。
- Http/Controllers/NotificationController.php — API 入口：store(), retry()。Controller 仅做验证与授权，委托 Service 层。
- Jobs/DeliverNotification.php — 旧兼容 Job（委托 ChannelManager）；保留以兼容早期测试。
- Jobs/DeliverTargetNotification.php — 按 target 投递的 Job（Outbox flush 使用）。
- Channels/ — ChannelContract, ChannelManager, HttpChannel：多渠道投递抽象层。
- Models/Notification.php, NotificationAttempt.php, NotificationTarget.php — 业务模型，与 Repository 交互。
- Models/Outbox.php — Outbox 模型（轻量化，记录待转发消息）。

config/
- notifications.php — 所有与投递、重试、outbox 相关的可配置项（详见文件）。

database/migrations/
- 包含 notifications、notification_attempts、notification_targets、outbox 等迁移文件。请按时间顺序阅读文件名。

documents/
- 集中存放 SA/SD、操作指南、实现计划与 OpenAPI 草案；所有面向运维/产品/架构的说明放在此处。

tests/
- Feature/ 包含关键流程的集成/功能测试（CreateNotificationTest, RetryNotificationTest 等）。

部署与运维要点
- Scheduler：推荐在 App\Console\Kernel 中添加 outbox:flush 调度，或用 supervisor 管理短间隔 flush worker。
- 队列：开发可用 sync/db；生产建议用 redis + horizon 或 rabbitmq 驱动。

联系方式
- 维护者：mikeah2011
