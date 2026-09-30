---
layout: default
title: Local Hooks
parent: Contributing
nav_order: 6
---

# Local Hooks

The repository provides optional local checks. CI remains the authoritative
quality gate, so hooks are designed to be fast and useful during normal work.

## Enable the hooks

From the repository root:

```bash
git config core.hooksPath .githooks
```

The hooks use the checked-out Composer and Node tools. Run `composer install` and
make sure `npx --no-install markdownlint-cli2 --version` works before enabling
them.

## What runs

| Hook | Checks |
|------|--------|
| `pre-commit` | PHP syntax for staged PHP, Markdown lint for staged Markdown, and harness validation |
| `pre-push` | Unit tests and harness validation |
| Claude `SessionStart` | Prints the current project context; it does not run heavy checks |

The hooks do not run the full PHPStan, Deptrac, PHPMD, Rector, integration, or
database checks. Run the [full quality checks](quality-checks) before opening a
pull request.

## Disable or restore

To use the repository without local hooks:

```bash
git config --unset core.hooksPath
```

Restore them later with the enable command above. A one-off bypass with
`--no-verify` is available for an emergency, but the skipped checks should be
run before pushing.
