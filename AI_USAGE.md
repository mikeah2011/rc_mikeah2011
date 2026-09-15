# AI 使用说明

## AI 提供的帮助

AI 用于提取 PDF 要求、比较技术栈、分析至少一次投递的失败窗口，并辅助生成 Laravel 迁移、实现和测试。关键帮助包括：

- 区分 Event、Channel、Provider Endpoint 和 Delivery，避免把 Airship、EDM、SMS、CRM 与库存塞进同一个抽象。
- 识别“供应商已执行但响应丢失”和“本地成功、Job 尚未确认”两个崩溃窗口。
- 检查接入幂等与供应商副作用幂等的差异。
- 提醒 URL 透传的 SSRF、重定向和队列 payload 泄密风险。
- 设计错误分类、有限退避、抖动、死信及人工重投轮次隔离测试。
- 根据测试结果修正 Eloquent 数据库默认值不会自动回填新 Model 实例的问题。

## 没有采纳的 AI 建议

- **Python + SQLite**：依赖少，但不符合最终确认的 Laravel/PHP 技术栈，也不足以表达 ToB 场景下 PostgreSQL 与 Laravel Queue 的实践。
- **Kafka + Redis + PostgreSQL**：没有吞吐证据时引入多个有状态组件，会增加部署和故障恢复成本。
- **Outbox + database queue + Scheduler**：通知表和 queue 使用同一个 PostgreSQL connection 时可以直接原子写入，再加 Outbox 属于重复保障。
- **通用 ChannelManager 和模板 DSL**：本服务只负责 HTTP 投递；Payload 由上游组装，提前抽象会制造错误的公共模型。
- **无限重试保证最终送达**：永久4xx和下线供应商会制造无界积压。
- **声称 exactly-once**：外部供应商没有参与本地事务，无法兑现。
- **第一版使用 Redis/Horizon**：会引入数据库与 Redis 双写；只有 database queue 出现实际瓶颈后才迁移。

## 自己做出的关键决策

- 将第一版定位为“已解析请求的可靠投递引擎”，不承担用户事件监听和供应商选择。
- 经人工确认后选择 Laravel 13、PHP 和 PostgreSQL database queue，替换 AI 最初生成的 Python 方案。
- 使用 Endpoint 注册表而不是 URL 透传，使凭据、超时、授权和出口目标由服务端控制。
- 单请求单 Endpoint；多供应商拆成独立 Delivery，避免部分成功语义和故障耦合。
- `jobs/failed_jobs` 保持 Laravel 基础设施表，另建 Delivery 与 Attempt 业务状态。
- Body 与 Header 可逆加密，API Key 只保存不可逆哈希。
- 成功记录保留30天、失败记录保留90天，在排障审计和容量/隐私间取平衡。

AI 输出均经过人工边界校准、测试验证与重构。最终方案追求能解释并兑现的可靠性，而不是组件或功能数量。
