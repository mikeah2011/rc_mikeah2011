# Release Notes

## v0.2.0 — 2026-09-15

本版本将项目目标收敛为：**接收业务系统提交的外部 HTTP(S) 通知请求，并尽可能可靠地投递到目标地址。**

### 主要调整

- 使用 Outbox + Laravel database queue 保存投递意图并异步处理。
- 修复 Outbox 先标记、后发布可能导致漏投的问题。
- 统一创建通知与人工重投的调度逻辑，增加投递轮次隔离。
- 对网络异常、`408`、`429` 和 `5xx` 进行有限退避重试；`2xx` 标记成功，永久错误标记失败。
- 保留原始 Body，不自动跟随 HTTP 重定向。
- 增加 Scheduler、Worker、Compose 启动方式和可靠性回归测试。
- 按“系统边界、可靠性与失败处理、取舍与演进”重写 SA/SD。

### 重要限制

- `202 Accepted` 只表示通知已被本服务持久化，不表示外部供应商已经成功执行。
- 系统采用允许重复的至少一次处理模型，不承诺外部业务恰好执行一次；有副作用的供应商接口应提供业务幂等能力。
- 当前只实现单请求、单 HTTP 目标，不包含邮件、短信、多目标广播或完整 DLQ。
- 调用方鉴权、完整 SSRF 防护、生产监控和真实 Broker 故障演练仍是上线前工作。

### 升级提示

默认配置为 `NOTIFICATIONS_USE_OUTBOX=true`、`QUEUE_CONNECTION=database`。升级后请确认数据库迁移已执行，并同时运行：

```bash
php artisan schedule:list
php artisan outbox:flush --limit=100
php artisan queue:work database --sleep=1 --tries=100 --timeout=30
```

详细说明：

- [系统分析与设计](documents/SA_SD.md)
- [启动与容器化](documents/SETUP.md)
- [Outbox 与持久化队列](documents/OUTBOX_BROKER.md)
- [完整变更记录](documents/CHANGELOG.md)
