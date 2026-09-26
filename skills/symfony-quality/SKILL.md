---
name: symfony-quality
description: Review or validate a Doctrine Doctor change against PHPUnit, PHPStan, ECS, PHPMD, Rector, Deptrac, and CI checks.
---

# Symfony Quality Skill

Read [Quality Checks](../../docs/guides/quality-checks.md). Run focused checks
first, then the full pre-PR set. Treat PHPStan and Deptrac findings as design
feedback: fix the seam or dependency direction instead of weakening the rule.

Done means the checks are green or every exception is explicit in the PR.
