# PHP Object Design Rules

These rules turn common object-oriented principles into decisions that fit
Doctrine Doctor. They guide design reviews; they are not a reason to add an
abstraction to every class.

## Boundaries and dependencies

- Keep domain policy independent from Symfony, Doctrine, Twig, and database
  drivers.
- Inject required collaborators through constructors. Depend on an interface
  only when there is a real variation, an external boundary, or a useful test
  seam.
- Do not use a service locator, global state, static factories, or hidden
  container lookups inside domain or application code.
- Keep adapters at the edge: translate framework, SQL, and filesystem data at
  the boundary, then pass typed project data inward.
- Keep services `final` unless inheritance is an explicit extension contract.

## Responsibility and variation

- Give a class one reason to change. An analyzer detects one coherent rule;
  orchestration, registration, rendering, and persistence stay elsewhere.
- Prefer composition and a small strategy interface when behavior varies by
  database platform or execution mode.
- Use a factory or registry when new analyzers should be added without editing
  a central conditional. Keep the factory thin.
- Use a decorator for cross-cutting behavior such as timing, logging, or
  deduplication. Do not create a subclass only to add cross-cutting behavior.
- Apply the Liskov Substitution Principle to analyzer contracts: every
  implementation must honor the same input, output, and failure semantics.

## Domain model and state

- Keep invariants in the object that owns the state. Prefer named operations
  over public setters that allow invalid intermediate states.
- Use small immutable value objects for identifiers, categories, severities,
  locations, and other values with rules of their own.
- Avoid primitive obsession: when a string, integer, array, or boolean carries a
  domain meaning, validation rule, or repeated group of fields, give it a named
  value object or a typed structure. Keep simple primitives for genuinely
  simple values.
- Initialize collections in entity constructors and expose the narrowest useful
  operation; do not leak mutable persistence collections.
- Use `readonly` for immutable values. Use PHP 8.4 asymmetric visibility or
  property hooks only when they express a real invariant and remain clearer than
  a named method.
- Use DTOs at framework or transport boundaries. Do not make domain entities
  mutable request bags.

## PHP 8.4 baseline

- Use `declare(strict_types=1)`, typed properties, parameter types, return
  types, enums, and constructor property promotion where they improve the
  contract.
- Prefer explicit `match` branches and exhaustive handling for finite domain
  states.
- Avoid dynamic properties, magic access, and nullable required dependencies.

## Review questions

1. What single responsibility and boundary does this class own?
2. Which dependency or behavior is expected to vary, and is the seam at the
   correct side of the dependency rule?
3. Can an invalid state be created through the public API?
4. Does the design make a focused unit test possible without booting Symfony or
   opening a database?
5. Would deleting the abstraction remove concentrated complexity, or only a
   pass-through wrapper?

## References

- [Symfony dependency injection guidance](https://symfony.com/doc/current/service_container/injection_types.html)
- [Symfony service and framework best practices](https://symfony.com/doc/current/best_practices.html)
- [Doctrine ORM best practices](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/best-practices.html)
- [Doctrine rich entity guidance](https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/getting-started.html)
- [PHP-FIG PSR-12](https://www.php-fig.org/psr/psr-12/)
- [PHP 8.4 release notes](https://www.php.net/releases/8.4/en.php)
