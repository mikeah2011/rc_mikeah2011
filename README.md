# 企业内部可靠 HTTP 通知服务

这是 AI Coding Assignment 的 Laravel 13 MVP：接收内部业务系统已经解析好的单目标 HTTP 投递命令，在 PostgreSQL 中原子保存 Delivery 和 Laravel database queue Job，再由 Worker 异步调用供应商 API。

## 1. 问题理解与系统边界

### 1.1 这不是用户事件平台

注册、付款和购买是上游理解的领域事件。本服务不监听埋点，也不决定营销规则：

```text
UserRegistered ------> Airship Push Delivery
                  \---> EDM Delivery
SubscriptionPaid ----> CRM Contact Delivery
OrderPurchased ------> Inventory Delivery
                              |
                              v
                    可靠 HTTP 通知服务
```

- Event：发生了什么，例如 `OrderPurchased`。
- Channel/Capability：通知能力，例如 App Push、Live Activity、EDM、SMS。
- Provider/Endpoint：由谁、向哪里发送，例如 Airship 或某 CRM API。
- Delivery：一个事件向一个 Endpoint 发起的一次独立可靠投递。

一次 API 请求只产生一个 Delivery。一个事件通知多个供应商时，上游使用不同幂等键提交多个请求，使各供应商的成功、重试和人工处置互不耦合。

### 1.2 本版解决

- API Key 鉴权、Client 识别和 Client 到 Endpoint 的授权。
- Endpoint 注册表管理 URL、Method、加密认证 Header、超时和重试策略。
- JSON、XML、表单或二进制 Body 的持久接收和加密存储。
- Delivery 与 database queue Job 同库事务写入；入队失败不返回成功。
- 异步 HTTP 投递、超时、错误分类、指数退避与随机抖动。
- 业务幂等、尝试审计、最终失败、状态查询和人工重投。
- 崩溃恢复、人工重投的轮次隔离，以及30/90天数据清理。

### 1.3 本版明确不解决

- **业务事件监听、供应商选择和 Payload 映射**：依赖业务语义，由上游负责。
- **恰好一次副作用**：供应商可能已处理请求但响应丢失，单方 HTTP 调用无法消除该窗口。
- **严格事件顺序**：库存等场景若要求顺序，需要上游版本号或未来增加分区键。
- **动态 OAuth、供应商 SDK、模板 DSL 和营销编排**：第一版缺少稳定需求，提前抽象会扩大凭据及协议管理复杂度。
- **多目标事务**：每个目标都是独立 Delivery，不定义部分成功的批次语义。
- **跨地域容灾、管理后台和租户计费**：不属于 MVP 闭环。

本服务只能保证收到 API 请求之后的可靠性。业务数据库提交成功、但尚未调用本服务就崩溃的问题，应由业务系统自己的 Transactional Outbox 或补偿任务解决。

## 2. 架构与核心设计

```text
Business Service
      | X-API-Key + endpoint_key + payload + idempotency_key
      v
Laravel API -- authorization / validation / idempotency
      | one PostgreSQL transaction
      +--> deliveries
      +--> jobs (Laravel database queue)
                |
                v
          Queue Worker
                |
                +--> endpoints (URL / encrypted credentials / policy)
                +--> delivery_attempts
                +--> Vendor HTTP(S) API
```

API 与 Worker 位于同一代码库，运行时可独立扩容。Laravel 自带的 `jobs`、`failed_jobs` 仅承担队列基础设施职责，不修改其结构；`deliveries` 才是查询、审计和人工处理的业务事实来源。

| 表 | 责任 |
| --- | --- |
| `api_clients` | Client 名称、API Key SHA-256 哈希、启用状态 |
| `endpoints` | URL、Method、加密静态 Header、超时和重试策略 |
| `api_client_endpoint` | Endpoint 使用授权 |
| `deliveries` | 加密 Body、幂等键、业务状态、轮次和最后错误 |
| `delivery_attempts` | 每次 HTTP 调用的状态码、耗时和脱敏错误 |
| `jobs / failed_jobs` | Laravel Queue 的待执行及最终失败记录 |

Job payload 只包含 `delivery_id` 和 `delivery_round`，不包含 Body、URL 或凭据。

## 3. API

业务接口均要求 `X-API-Key`。

### 创建 Delivery

`POST /api/v1/deliveries`

```json
{
  "endpoint_key": "inventory-primary",
  "idempotency_key": "order:42:inventory:v1",
  "content_type": "application/json",
  "headers": {"X-Correlation-ID": "trace-42"},
  "payload": {"sku": "A-1", "delta": -1}
}
```

- `payload` 用于任意 JSON；精确 XML、表单或二进制字节使用 `body_base64`，两者必须二选一。
- 动态 Header 必须在 Endpoint allowlist 中，不能覆盖 Authorization、Host、Content-Length、Idempotency-Key 等受控 Header。
- 新建和幂等重放均返回 `202`，`created` 表示是否为新记录。
- 同一 Client 的相同幂等键配不同内容返回 `409`。

```json
{
  "id": "0199...",
  "status": "pending",
  "attempts_count": 0,
  "created_at": "2026-09-15T08:00:00+00:00",
  "updated_at": "2026-09-15T08:00:00+00:00",
  "created": true
}
```

其他接口：

- `GET /api/v1/deliveries/{id}`：仅所属 Client 可查，不返回 Body、凭据或请求哈希。
- `POST /api/v1/deliveries/{id}/retry`：仅所属 Client 的最终失败任务可重投。
- `GET /healthz`：进程存活；`GET /readyz`：数据库可访问。

## 4. 可靠性与失败处理

### 4.1 至少一次

API 仅在 Delivery 和 database Job 的同一 PostgreSQL 事务提交后返回 `202`。Worker 收到2xx后才标记成功。

若供应商已完成操作但响应丢失，或 Worker 在保存成功状态前崩溃，队列会再次投递。因此每次调用都携带稳定的 `Idempotency-Key`；但供应商是否去重仍取决于对方协议。接入幂等不能实现外部副作用 exactly-once。

若 Delivery 已成功、但 Job 尚未从队列删除时崩溃，重放 Job 会检查终态并直接结束。Worker 在结果落库前中断时，旧 attempt 被标记为 `abandoned`，恢复任务创建新 attempt。

### 4.2 失败分类

| 结果 | 策略 |
| --- | --- |
| 2xx | 成功；不保存响应 Body |
| 408、429、5xx | 有限重试 |
| DNS、连接、TLS、读取超时 | 有限重试 |
| 其他4xx | 永久失败 |
| 3xx | 不跟随重定向并失败，避免认证 Header 外泄 |
| Endpoint 禁用 | 不发请求，立即失败 |

重试按 Endpoint 退避数组逐级延长，并加入50%到150%抖动。达到最大次数后 Delivery 进入 `failed`，Job 进入 `failed_jobs`。运维确认请求仍有效、供应商恢复后才能人工重投。

Endpoint HTTP timeout 最大30秒，Job timeout 40秒，database queue `retry_after` 90秒，避免同一 Job 在原 Worker 尚未退出时被另一 Worker 领取。

## 5. 工程决策与取舍

### 为什么选择 Laravel + PostgreSQL database queue

- Laravel 已提供 HTTP Client、Queue Worker、失败队列、迁移、加密 cast、验证和测试设施。
- PostgreSQL 同时保存业务状态和 Job，使二者能在一个本地事务中提交，不存在数据库与 Redis/Kafka 的双写窗口。
- 第一版只维护 PostgreSQL 一个有状态组件，部署和恢复路径清晰。

Redis Queue 吞吐更高，但数据库与 Redis 无法原子双写，需要 Outbox；SQS 同样需要 Outbox 与可见性超时配置；Kafka 适合高吞吐事件日志，但对当前 MVP 过重。

### 主动拒绝的复杂度

- 不同时使用 Outbox 和同库 database queue。
- 不抽象 Email、SMS、Push、CRM 等 Channel 类；对投递内核它们都是 Endpoint。
- 不加入 Outbox Scheduler、Redis、Horizon、Kafka、工作流引擎或模板语言。
- 不无限重试，也不对所有4xx重试。
- 不提供 Endpoint 管理 API，避免再建设管理员权限和密钥展示协议。

### 安全判断

- 调用方只传 `endpoint_key`，不能传 URL，避免服务成为通用 SSRF 代理。
- Endpoint 仅允许通过受控命令写入 HTTPS URL；生产仍需要出口 ACL。
- API Key 只存哈希；静态 Header 和 Body 使用 APP_KEY 加密，查询接口不回显。
- Endpoint 对 Client 显式授权；跨 Client 查询返回404。
- 认证 Header 通过隐藏交互录入，避免进入 shell history。

## 6. 扩展性与演进

先观察 queue depth、oldest-job age、Endpoint 失败率、429比例、投递耗时和 failed Delivery 数量，再演进：

1. 增加 Endpoint 级速率限制、并发隔离和熔断。
2. API 与 Worker 分部署并水平扩容，按 Endpoint 或优先级拆分队列。
3. PostgreSQL Queue 成为瓶颈后迁移 Redis/SQS，同时引入 Transactional Outbox。
4. 协议稳定后增加版本化 Adapter、密钥管理系统和独立控制面。
5. 只有出现明确需求后才引入事件路由、用户偏好、模板或营销编排。

## 7. 运行与验证

要求 PHP 8.3+、Composer、PostgreSQL：

```bash
docker compose up -d postgres
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
```

创建 Endpoint、Client 并授权：

```bash
php artisan notifications:endpoint-upsert inventory-primary \
  --vendor="Inventory Inc" \
  --url="https://inventory.example/api/stock" \
  --allowed-header=X-Correlation-ID
php artisan notifications:client-create order-service
php artisan notifications:client-grant order-service inventory-primary
```

分别启动：

```bash
php artisan serve
php artisan queue:work database --queue=notifications --timeout=40 --tries=100
php artisan schedule:work
```

```bash
php artisan test --compact
```

测试默认使用内存 SQLite 快速反馈；提交前另用 PostgreSQL 跑全套测试，验证目标数据库事务与约束行为。
