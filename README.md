# API 通知系统（Notifications MVP）

最小可行实现（MVP）实现目标：接收业务系统提交的通知请求（URL、Method、Headers、Body），持久化并异步投递到目标 HTTP(S) 端点，提供尝试记录与人工重投能力。MVP 在本项目语境中表示“最小可行实现（Minimum Viable Implementation）”，不是系统名称。

## 主要功能（MVP）
- 接收并验证通知请求；支持幂等键以防重复创建。
- 持久化通知记录与投递尝试记录（notification_attempts）。
- 使用 database queue 异步投递；实现指数退避、Retry-After 支持与最大重试次数。
- 提供人工重投接口：POST /api/notifications/{id}/retry。
- 配置驱动（config/notifications.php），并包含开发环境（SQLite）与容器化（docker-compose）说明。

## 快速开始（开发/验证，SQLite）
1. 复制环境示例并生成应用密钥：
   cp .env.example .env
   php artisan key:generate
2. 切换到 SQLite（可编辑 .env）：
   DB_CONNECTION=sqlite
   DB_DATABASE=database/database.sqlite
   touch database/database.sqlite
3. 安装依赖并运行迁移：
   composer install --no-interaction
   php artisan migrate --force
4. 运行 Feature 测试（示例）：
   php artisan test --testsuite=Feature

## 容器化（可选）
仓库包含 docker-compose.yml 和 Dockerfile，可在有 Docker 环境的机器上用以下命令启动：

  docker compose up -d --build
  docker compose exec app php artisan migrate --force
  docker compose exec app php artisan test --testsuite=Feature

详细步骤见 SETUP.md。

## 文档索引
- CHANGELOG.md — 仓库专用的变更记录与策略调整文档。
- TECH_STACK.md — 技术栈选型记录与决策历史。
- plan.md — 实施计划与分批规则。
- AI_MODEL_POLICY.md — AI 模型调用与权限放开策略。
- SETUP.md — 本地与容器化启动说明。

## 开发与分批规则（摘要）
- 以小批次提交为原则：每批包含实现、迁移、测试与文档，避免一次性大包提交。
- 所有会影响运行时或 DB 的变更需包含对应迁移与测试。
- 敏感操作（生产发布、远程推送）需人工批准，详见 AI_MODEL_POLICY.md 与 plan.md。

## 联系与贡献
- 维护者：项目作者（本地仓库 rc_mikeah2011）。
- 提交规范：请遵循仓库中的分批实现规则；不提交敏感凭据或 vendor 目录。

## 许可证
MIT


Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
