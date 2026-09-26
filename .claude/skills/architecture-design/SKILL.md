---
name: architecture-design
description: Shape Symfony and Doctrine changes around explicit seams, deep modules, SOLID, and domain boundaries.
---

# Architecture Design Skill

1. Read [MEMORY.md](../../../MEMORY.md), [CONTEXT.md](../../../CONTEXT.md), the
   [architecture guide](../../../docs/advanced/architecture.md), and the
   [architecture rules](../../../docs/rules/architecture.md).
2. Identify the module being changed, its interface, its seam, and its adapters.
3. Apply the deletion test: removing the module should reveal concentrated
   complexity, not just remove a pass-through wrapper.
4. Prefer the smallest coherent change. Introduce an interface only for a real
   variation, an external dependency, or a useful test seam.
5. Keep domain policy independent from Symfony and Doctrine adapters.
6. Validate dependency direction with `composer deptrac`, types with
   `composer phpstan`, and behavior with a focused test.
7. Record an ADR only when the decision is hard to reverse, surprising, and
   based on a real trade-off.

Done means the seam is explicit, callers stay simple, and the change has a
focused validation path.
