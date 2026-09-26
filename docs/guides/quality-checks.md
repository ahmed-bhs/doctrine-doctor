---
layout: default
title: Quality Checks
parent: Contributing
nav_order: 5
---

# Quality Checks

Use the smallest useful check while developing, then run the full set before a
pull request.

| Question | Check |
|----------|-------|
| Does the PHP parse? | `composer lint` |
| Does the code follow project style? | `composer ecs` |
| Are types and framework contracts correct? | `composer phpstan` |
| Is the code structure within the allowed layers? | `composer deptrac` |
| Is the code free from configured design problems? | `composer phpmd` |
| Would automated refactoring change it? | `composer rector` |
| Does the behavior work? | `composer test` |
| Are the Markdown pages valid? | `composer markdown-lint` |
| Do static Doctrine checks pass? | `php bin/console doctrine:doctor:analyze --fail-on=warning` |

The full pre-PR set is:

```bash
composer lint
composer ecs
composer phpstan
composer phpmd
composer rector
composer deptrac
composer test
composer markdown-lint
```

Run `doctrine:doctor:analyze --with-database` only in an environment whose
database is intentionally part of the check. Report skipped checks and their
reason in the PR.

The hooks are optional local feedback. Enable them with:

```bash
git config core.hooksPath .githooks
```

CI remains the authoritative check.
