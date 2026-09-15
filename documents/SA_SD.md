<a id="sa_sd"></a>
# 系统架构与系统设计（SA/SD）

## 概要
本文件为“API 通知系统（Notifications MVP）”的系统架构与设计文档草案，面向开发/运维人员，包含边界、数据模型、关键流程、失败处理与运维要点。

## 系统边界
- 提供 HTTP API 接收通知请求（POST /api/notifications）。
- 持久化存储通知与投递尝试（notifications, notification_attempts）。
- 异步投递器（Laravel Job）负责对外 HTTP 投递、记录 attempts、并在失败时按重试策略重入队。
- 管理端点支持人工重投（POST /api/notifications/{id}/retry）。

## 关键组件
1. API 层（Laravel Controller）
   - 幂等性：通过 (client_id, idempotency_key) 唯一约束实现。
   - 原子性：在事务内保存 notification 并 dispatch Job（database queue）。

2. 数据库
   - notifications：id(uuid), client_id, idempotency_key, payload(JSON), status(enum: pending/processing/succeeded/failed), delivery_round(int), next_attempt_at(datetime)
   - notification_attempts：id, notification_id, attempt_number, status_code, response_body, started_at, finished_at, error

3. Worker / Job
   - DeliverNotification Job：执行 HTTP 请求、记录 Attempt、判定成功或需重试。
   - 重试策略：支持 Retry-After（秒或 HTTP-date）、指数退避 + 抖动、config 可调的 max_attempts。

4. Config
   - config/notifications.php 提供参数：max_attempts, backoff_base_seconds, max_backoff_seconds, jitter_pct。

## 投递语义与失败处置
- 2xx = 成功（标记通知为 succeeded）。
- 408/429/5xx -> 记录 attempt 并重试（若未超过 max_attempts）。
- 4xx（非 408/429）-> 视为永久失败（标记 failed，不再重试）。
- 3xx 不自动跟随，视为失败并按策略处理。

## 人工重投
- 仅允许对 status = failed 的通知触发重投。
- 实现：在事务中锁行，increment delivery_round，删除/标记旧 attempts 为历史，重置 next_attempt_at 并 dispatch 新 Job。

## 数据与伸缩建议
- 本地/开发：SQLite 足够验证行为。
- 生产：推荐 PostgreSQL（支持事务+入队一致性），队列使用数据库或 Redis（根据吞吐与可见性需求）。
- 高吞吐/可靠性建议：采用 Broker + Outbox 组合（推荐 Redis + Outbox，或 RabbitMQ/Kafka/SQS 视需求）。详见 documents/OUTBOX_BROKER.md。

## 监控与运维
- 指标：attempts_total, attempts_failed, attempts_succeeded, queue_job_duration, queue_depth。
- 告警：高失败率、queue depth 持续增长、单个目标大量 4xx/5xx。

## 安全建议
- 对外投递做 SSRF 白名单，限制允许的目标域名/IP。
- 投递请求避免将敏感凭证直接写入日志，使用占位/掩码。

---

（此文件为草稿；实现细节请参考 code 中 models、migrations 与 app/Jobs/DeliverNotification.php）
