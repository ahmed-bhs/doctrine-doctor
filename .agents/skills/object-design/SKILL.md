---
name: object-design
description: Choose object-oriented boundaries, value objects, and design patterns for Doctrine Doctor without adding ceremonial abstractions.
---

# Object Design Skill

1. Read [PHP object design rules](../../../docs/rules/php-oop.md),
   [architecture rules](../../../docs/rules/architecture.md), and the relevant
   architecture guide before changing a class boundary.
2. State the responsibility, invariant, and expected variation before choosing
   a pattern.
3. Prefer a plain class and composition first. Add an interface only for a
   real variation, external boundary, or focused test seam.
4. Use a value object when a value has validation, formatting, identity, or
   behavior of its own. Keep it immutable when possible.
5. Use Strategy for a stable algorithm contract with interchangeable behavior,
   Factory or Registry for extensible creation, Adapter at framework/database
   boundaries, and Decorator for cross-cutting behavior.
6. Keep entities responsible for their invariants and named state changes.
   Keep repositories focused on persistence queries and application services
   focused on orchestration.
7. Apply the deletion test: if removing the abstraction only removes a
   pass-through wrapper, do not introduce it.
8. Validate with a focused test, `composer phpstan`, and `composer deptrac` when
   a dependency direction or public contract changes.

Do not use patterns as a catalog. The smallest design that makes the boundary
clear and the behavior testable is the preferred result.
