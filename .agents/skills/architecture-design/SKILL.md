---
name: architecture-design
description: Shape Symfony and Doctrine changes around explicit seams, deep modules, SOLID, and domain boundaries.
---

# Architecture Design Skill

1. Read [MEMORY.md](../../../MEMORY.md), [CONTEXT.md](../../../CONTEXT.md), the
   [architecture guide](../../../docs/advanced/architecture.md), and the
   [architecture rules](../../../docs/rules/architecture.md).
2. Identify the module being changed, its interface, its seam, and its adapters.
3. Read the [PHP object design rules](../../../docs/rules/php-oop.md) when the
   change introduces or reshapes a class, interface, entity, or value object.
4. Apply the deletion test: removing the module should reveal concentrated
   complexity, not just remove a pass-through wrapper.
5. Prefer the smallest coherent change. Introduce an interface only for a real
   variation, an external dependency, or a useful test seam.
6. Keep domain policy independent from Symfony and Doctrine adapters.
7. Prefer composition and use the smallest correct pattern: Strategy for
   stable variation, Factory or Registry for extensible creation, Adapter at
   external boundaries, Decorator for cross-cutting behavior, and value objects
   for values with their own invariants.
8. Keep entities responsible for invariants and named state changes; keep
   repositories focused on persistence queries and application services focused
   on orchestration. Use `final` services unless inheritance is an extension
   contract.
9. Validate dependency direction with `composer deptrac`, types with
   `composer phpstan`, and behavior with a focused test.
10. Record an ADR only when the decision is hard to reverse, surprising, and
   based on a real trade-off.

Done means the seam is explicit, callers stay simple, and the change has a
focused validation path.
