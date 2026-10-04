# Contributing to laravel-api-usage

Thanks for your interest in contributing to this package. We welcome bug reports, fixes, tests, documentation improvements, and thoughtful discussions.

## How to contribute

You can contribute in several ways:

- report a bug or issue
- suggest an improvement or new feature
- submit a bug fix or small refactor
- improve documentation or examples
- add or improve tests

Before doing substantial work, please open an issue so we can align on scope and approach.

## Local development

### Prerequisites

- PHP 8.2 or newer
- Composer
- Redis, only for the optional integration tests below (the main suite fakes it)
- A supported Laravel app/test environment via `orchestra/testbench`

### Setup

```bash
git clone https://github.com/systemverk/laravel-api-usage.git
cd laravel-api-usage
composer install
```

### Run checks

```bash
composer test
composer analyse
```

#### Other databases

By default the suite runs against in-memory SQLite. CI also runs it against
MySQL, MariaDB and PostgreSQL. To do the same locally, start a database and
point the tests at it:

```bash
docker run -d --rm -p 33061:3306 -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=api_usage_test mysql:8.4
TEST_DB_DRIVER=mysql TEST_DB_PORT=33061 composer test
```

`TEST_DB_DRIVER` is `sqlite` (default), `mysql` (also for MariaDB) or `pgsql`.
`TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_DATABASE`, `TEST_DB_USERNAME` and
`TEST_DB_PASSWORD` override the connection details.

#### Redis integration tests

The main suite fakes Redis. `tests/Integration` runs the flush and buffer paths
against a real Redis through both phpredis and predis, because the two clients
differ in ways a fake cannot show. They are skipped unless you point them at a
Redis:

```bash
docker run -d --rm -p 6379:6379 redis:7
TEST_REDIS_PORT=6379 vendor/bin/phpunit tests/Integration
```

The phpredis tests also need `ext-redis`; each class skips itself when its
client is missing. `TEST_REDIS_HOST` defaults to `127.0.0.1`.

The project also provides a combined CI-like command:

```bash
composer ci
```

## Suggested workflow

1. Fork the repository and create a branch for your change.
2. Keep changes focused and avoid unrelated refactors.
3. Add or update tests for behavior changes.
4. Run the relevant test suite and static analysis before submitting.
5. Open a pull request with a clear description of the problem and fix.

## Coding guidelines

- Prefer small, readable changes.
- Preserve the package's existing architecture and naming conventions.
- Keep changes backward compatible unless a breaking change is explicitly discussed.
- Include tests for bug fixes and behavior changes.
- Avoid introducing unnecessary dependencies.

## Commit messages

A concise, descriptive commit message is preferred. For example:

- `fix: handle missing redis connection in middleware`
- `test: cover actor resolution for guest requests`
- `docs: clarify scheduler and flush behavior`

## Pull requests

Please include:

- a short summary of the change
- the rationale behind it
- any relevant issue references
- verification steps or commands run

We may ask for adjustments if the change needs more edge-case coverage or clearer documentation.

## Reporting issues

When filing an issue, include:

- the package version
- PHP and Laravel versions
- Redis driver and configuration details if relevant
- the exact reproduction steps
- the expected behavior and the actual behavior
- any relevant logs or stack traces

## Security

If you believe you have found a security vulnerability, please do not open a public issue. Follow the guidance in [SECURITY.md](SECURITY.md).

Thank you for helping improve the project.
