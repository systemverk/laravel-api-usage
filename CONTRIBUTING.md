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
- Redis (for the package's runtime behavior and tests)
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
