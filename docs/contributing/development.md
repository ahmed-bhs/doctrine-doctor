---
layout: default
title: Development
parent: Contributing
nav_order: 2
---

# Development Setup

Complete guide to setting up Doctrine Doctor for local development.

---

## Prerequisites

- **PHP**: 8.2 or higher
- **Composer**: 2.0+
- **Git**: Latest version
- **IDE**: PHPStorm, VS Code, or your preference

---

## Fork and Clone

### 1. Fork the Repository

Click the "Fork" button on [GitHub](https://github.com/ahmed-bhs/doctrine-doctor)

### 2. Clone Your Fork

```bash
git clone https://github.com/YOUR-USERNAME/doctrine-doctor.git
cd doctrine-doctor
```

### 3. Add Upstream Remote

```bash
git remote add upstream https://github.com/ahmed-bhs/doctrine-doctor.git
```

---

## Install Dependencies

```bash
composer install
```

This installs:

- PHPUnit for testing
- PHPStan for static analysis
- PHP-CS-Fixer for code style
- Development dependencies

---

## Project Structure

```text
doctrine-doctor/
├── src/
│   ├── Analyzer/          # All analyzers
│   │   ├── Performance/   # Performance analyzers
│   │   ├── Security/      # Security analyzers
│   │   ├── Integrity/     # Data integrity analyzers
│   │   └── Configuration/ # Configuration analyzers
│   ├── Collector/         # Data collectors
│   ├── Factory/           # Issue factories
│   ├── Model/             # Domain models
│   ├── Template/          # Template rendering
│   └── DoctrineDoctorBundle.php
├── tests/                 # Unit and integration tests
├── config/                # Bundle configuration
└── docs/                  # Documentation
```

---

## Running Tests

### All Tests

```bash
vendor/bin/phpunit
```

### Specific Test Suite

```bash
vendor/bin/phpunit --testsuite=unit
vendor/bin/phpunit --testsuite=integration
```

### Single Test File

```bash
vendor/bin/phpunit tests/Analyzer/Performance/NPlusOneAnalyzerTest.php
```

### With Coverage

```bash
vendor/bin/phpunit --coverage-html coverage/
```

Open `coverage/index.html` in your browser.

---

## Static Analysis

### Run PHPStan

```bash
vendor/bin/phpstan analyse
```

### PHPStan Level

Doctrine Doctor uses **Level 8** (maximum strictness):

```neon
# phpstan.neon
parameters:
    level: 8
```

---

## Code Style

### Check Code Style

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
```

### Auto-Fix Code Style

```bash
vendor/bin/php-cs-fixer fix
```

### Configuration

Code style follows PSR-12:

```php
// .php-cs-fixer.dist.php
<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
```

---

## Development Workflow

### 1. Create Feature Branch

```bash
git checkout -b feature/my-new-analyzer
```

### 2. Make Changes

Edit files in `src/` directory.

### 3. Run Quality Checks

```bash
# Tests
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/php-cs-fixer fix
```

### 4. Commit Changes

```bash
git add .
git commit -m "feat: add XYZ analyzer"
```

### 5. Push to Your Fork

```bash
git push origin feature/my-new-analyzer
```

### 6. Create Pull Request

Go to GitHub and create a PR from your fork to the main repository.

---

## Testing with a Real Symfony Project

### 1. Create Test Symfony Project

```bash
symfony new test-app
cd test-app
```

### 2. Link Local Doctrine Doctor

Edit `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../doctrine-doctor"
        }
    ]
}
```

### 3. Require Local Version

```bash
composer require ahmed-bhs/doctrine-doctor:@dev --dev
```

### 4. Test Your Changes

Create entities and controllers, then check the Web Profiler.

---

## Debugging

### Enable Debug Output

```yaml
# config/packages/dev/doctrine_doctor.yaml
doctrine_doctor:
    profiler:
        show_debug_info: true
```

### Dump Variables

```php
<?php

use Symfony\Component\VarDumper\VarDumper;

VarDumper::dump($issues);
```

### Xdebug

Configure Xdebug in `php.ini`:

```ini
zend_extension=xdebug.so
xdebug.mode=debug
xdebug.start_with_request=yes
```

---

## IDE Configuration

### PHPStorm

1. **Install Symfony Plugin**
2. **Configure PHP Interpreter**: Settings → PHP → CLI Interpreter
3. **Enable PHPStan**: Settings → PHP → Quality Tools
4. **Configure Code Style**: Settings → Editor → Code Style → PHP

Import `.editorconfig` for automatic formatting.

### VS Code

Install extensions:

```bash
code --install-extension bmewburn.vscode-intelephense-client
code --install-extension wongjn.php-sniffer
code --install-extension sanderrock.phpstan
```

---

## Continuous Integration

GitHub Actions runs on every push:

```yaml
# .github/workflows/ci.yml
name: CI

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install
      - run: vendor/bin/phpunit
      - run: vendor/bin/phpstan analyse
```

---

## Common Issues

### Composer Install Fails

```bash
# Clear cache
composer clear-cache

# Install with verbose output
composer install -vvv
```

### PHPStan Errors

```bash
# Clear result cache
vendor/bin/phpstan clear-result-cache

# Run again
vendor/bin/phpstan analyse
```

### Tests Fail

```bash
# Clear test cache
php bin/phpunit --cache-result-file=/tmp/phpunit.cache

# Run with verbose output
vendor/bin/phpunit --verbose
```

---

## Documentation

### Update Documentation

Edit files in `docs/` directory using Markdown.

### Preview Documentation Locally

```bash
# Install MkDocs
pip install mkdocs-material

# Serve locally
mkdocs serve
```

Open http://127.0.0.1:8000

### Build Documentation

```bash
mkdocs build
```

---

## Release Process (Maintainers Only)

1. Update version in code
2. Update CHANGELOG.md
3. Create git tag
4. Push to GitHub
5. Create GitHub Release
6. Packagist auto-updates

---

## Getting Help

- **Questions**: [GitHub Discussions](https://github.com/ahmed-bhs/doctrine-doctor/discussions)
- **Issues**: [GitHub Issues](https://github.com/ahmed-bhs/doctrine-doctor/issues)
- **Documentation**: [Read the Docs](https://ahmed-bhs.github.io/doctrine-doctor/)

---

**[← Contributing Overview](overview)** | **[Creating Analyzers →](creating-analyzers)**
