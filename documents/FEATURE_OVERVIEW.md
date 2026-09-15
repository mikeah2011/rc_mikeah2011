<a id="feature_overview"></a>
# 功能说明

本项目是内部 HTTP 通知服务：业务系统提交供应商的 URL、Method、Headers 和原始字符串 Body，服务保存后异步投递。广告、CRM、库存等场景使用相同的 HTTP 投递流程。

## 核心功能

| 功能 | 行为 |
|---|---|
| 接收通知 | `POST /api/notifications`；持久化后返回 `202` 和通知 ID，不等待供应商 |
| 提交去重 | 提供调用方标识和幂等键时，同键同内容返回原记录，不同内容返回 `409`；不替代供应商侧幂等 |
| 异步投递 | 默认 Outbox + database queue，Scheduler 发布、Worker 消费 |
| 原始请求 | 保存字符串 Body，保留首尾空白，不按 JSON 二次编码；Headers 由业务方提供 |
| 有限重试 | 网络异常、`408 / 429 / 5xx` 延迟重试，支持 `Retry-After` 和指数退避 |
| 最终状态 | `2xx` 为 `delivered`；永久错误或预算耗尽为 `failed`；等待投递/重试为 `pending` |
| 人工重投 | `POST /api/notifications/{id}/retry`，仅重投失败通知，创建新轮次并保留历史尝试 |
| 重复与旧任务 | 检查轮次和最新状态，同一通知串行执行；不承诺外部副作用恰好一次 |
| 尝试记录 | 保存 HTTP 状态、耗时、错误及时间，不向业务方返回完整供应商响应 |

## 代码定位

| 路径 | 职责 |
|---|---|
| `app/Http/Controllers/NotificationController.php` | 接收与重投接口响应 |
| `app/Services/NotificationService.php` | 创建、提交幂等比较、重投事务 |
| `app/Services/NotificationDispatcher.php` | 共用投递意图写入、队列与超时配置约束 |
| `app/Console/Commands/FlushOutbox.php` | 有界扫描、先发布后标记 |
| `app/Jobs/DeliverNotification.php` | 轮次检查、并发互斥、延迟释放与队列失败处理 |
| `app/Jobs/DeliverTargetNotification.php` | 旧类名兼容入口，非空 target 明确不支持 |
| `app/Channels/HttpChannel.php` | HTTP 调用、响应分类、重试时间计算 |
| `routes/console.php` | Laravel Scheduler 定时任务 |
| `config/notifications.php` | 投递预算、退避、超时与 Outbox 配置 |

## 范围限制

目前不包含邮件/短信、多目标广播、供应商签名或 OAuth 刷新、严格事件顺序、完整 Outbox DLQ 和管理后台。状态记录存在数据库中，不应把规划中的查询接口或运维工具当作已经实现。

调用方鉴权、SSRF 出口约束、敏感信息保护、并发提交幂等的完整冲突恢复、人工重投请求幂等与生产故障演练仍需后续批次完善，不宜直接面向公网部署。

设计依据见 [SA/SD](SA_SD.md)，启动方式见 [SETUP](SETUP.md)，开发安排见 [PLAN](PLAN.md)。
