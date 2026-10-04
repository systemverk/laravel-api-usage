## [1.2.0](https://github.com/systemverk/laravel-api-usage/compare/v1.1.0...v1.2.0) (2026-10-04)

### ✨ Features

* Add Redis integration tests and update contributing guidelines for local setup ([e36fea3](https://github.com/systemverk/laravel-api-usage/commit/e36fea31b4902f10eb7e1134b272c7b84e7b88c1))
* Enhance API usage tracking with rejected events handling and improved flush isolation ([0e29974](https://github.com/systemverk/laravel-api-usage/commit/0e2997439ba524e069d5a4c7ba34df660fdf9388))
* Introduce DatabaseWriter and RedisBuffer for API usage tracking ([1416047](https://github.com/systemverk/laravel-api-usage/commit/14160476696ec2cf89d280c492c4775ed8e164cd))

### ♻️ Refactoring

* optimize API usage request and summary migrations by removing redundant indexes and improving index definitions ([8dd205e](https://github.com/systemverk/laravel-api-usage/commit/8dd205e6797feb9f80b21a96357792765ab86b1f))

## [1.1.0](https://github.com/systemverk/laravel-api-usage/compare/v1.0.1...v1.1.0) (2026-09-22)

### ✨ Features

* add actor-period index migration and corresponding tests ([c073f45](https://github.com/systemverk/laravel-api-usage/commit/c073f45ea26b5ca7476020b87c095af072342bdb))

## [1.0.1](https://github.com/systemverk/laravel-api-usage/compare/v1.0.0...v1.0.1) (2026-09-06)

### 🐛 Bug Fixes

* correct Redis member handling in FlushApiUsage and tests ([4fdfbbb](https://github.com/systemverk/laravel-api-usage/commit/4fdfbbb475dedcce320648cf9bd4fb723b8f730b))

## 1.0.0 (2026-09-06)

### ✨ Features

* trigger initial release ([064ca3b](https://github.com/systemverk/laravel-api-usage/commit/064ca3bbb35087149c19e3b92a3e2dfdec93a671))

### 🐛 Bug Fixes

* specify version for conventional-changelog-conventionalcommits plugin ([61e23d7](https://github.com/systemverk/laravel-api-usage/commit/61e23d7a10ea2f7567b132c9674fb7dc503ad212))

### 📝 Documentation

* add CONTRIBUTING.md and SECURITY.md files ([0617bd2](https://github.com/systemverk/laravel-api-usage/commit/0617bd2fe49652c2621ee0036746ecb321e93a60))

### 🤖 CI/CD

* update Laravel version constraints in CI configuration and composer files ([6fc765c](https://github.com/systemverk/laravel-api-usage/commit/6fc765c3e6a96dae2286f8cba8a7479a69034f9c))
