---
name: symfony-quality
description: Review or validate a Doctrine Doctor change against PHPUnit, PHPStan, ECS, PHPMD, Rector, Deptrac, and CI checks.
---

# Symfony Quality Skill

1. Read [Quality Checks](../../../docs/guides/quality-checks.md) and the
   [architecture rules](../../../docs/rules/architecture.md).
2. Map the change to a module, interface, seam, and adapter before adding a
   Symfony service, listener, or compiler pass.
3. Keep framework integration at the adapter or configuration edge.
4. Run focused checks first, then the full pre-PR set. Treat PHPStan and
   Deptrac findings as design feedback: fix the seam or dependency direction
   instead of weakening the rule.
5. Report checkpoints, trade-offs, and residual risk in the PR.

Done means the checks are green or every exception is explicit in the PR.
