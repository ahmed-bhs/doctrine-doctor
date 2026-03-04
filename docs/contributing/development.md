---
layout: default
title: Development
parent: Contributing
nav_order: 2
---

# Development Setup

Local setup guide to contribute efficiently to Doctrine Doctor.

---

## Prerequisites

- PHP 8.4+
- Composer 2+
- Git

---

## Clone

```bash
git clone https://github.com/YOUR-USERNAME/doctrine-doctor.git
cd doctrine-doctor
git remote add upstream https://github.com/ahmed-bhs/doctrine-doctor.git
```

---

## Install

```bash
composer install
```

---

## Quality Commands

Use the project Composer scripts:

```bash
# Tests
composer test

# Static analysis
composer phpstan

# Coding standards (ECS)
composer ecs

# Complete checks
composer check
```

Auto-fix commands:

```bash
composer cs:fix
```

---

## Project Structure

```text
doctrine-doctor/
├── src/
│   ├── Analyzer/
│   ├── Collection/
│   ├── DTO/
│   ├── Factory/
│   ├── Issue/
│   ├── Suggestion/
│   ├── Template/
│   └── ValueObject/
├── tests/
├── config/
├── docs/
└── CHANGELOG.md
```

---

## Typical Workflow

- Create a branch:

```bash
git checkout -b feature/my-change
```

- Implement changes + focused tests
- Run `composer check`
- Commit:

```bash
git add .
git commit -m "feat: add XYZ analyzer"
```

- Push + open PR

---

## Debugging

Enable bundle debug options:

```yaml
doctrine_doctor:
    profiler:
        show_debug_info: true
    debug:
        enabled: true
        internal_logging: true
```

Note: `internal_logging` adds overhead; use it only when investigating issues.

---

## Local Integration Test (Symfony app)

```bash
symfony new test-app
cd test-app
```

In the app `composer.json`:

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

Then:

```bash
composer require --dev ahmed-bhs/doctrine-doctor:@dev
```

---

## Before Opening PR

- [ ] `composer check` passes
- [ ] docs updated when behavior/config changes
- [ ] root changelog (`CHANGELOG.md`) updated when relevant

---

**[Overview →](overview)** | **[Creating Analyzers →](creating-analyzers)**
