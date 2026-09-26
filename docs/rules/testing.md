# Testing Rules

## Test pyramid

- Keep most tests fast and isolated around domain objects, parsers, factories,
  and analyzers.
- Add fewer integration tests for Symfony wiring, Doctrine metadata, template
  rendering, and compiler passes.
- Keep platform and end-to-end tests small and focused on behavior that cannot
  be proved at a lower level.

## Behavior over implementation

- Test through the narrowest public seam that proves the behavior.
- Name tests after an observable outcome, not a private method or collaborator
  call.
- Use independent expected values and representative fixtures.
- For an analyzer, cover a finding, a no-finding case, the threshold or edge
  case, and the regression that motivated the change.

## No mocks by default

- Do not mock Doctrine Doctor classes, internal collaborators, value objects, or
  collections. Prefer real objects, fixtures, in-memory implementations, or a
  small fake at the seam.
- Use a mock only for a genuine external boundary that is expensive,
  nondeterministic, destructive, or impossible to run in the test environment
  (for example time, network, filesystem, or a third-party service).
- Never verify internal call counts or call order. If an interaction is the
  behavior, make it an explicit port and test that port contract.
- Do not boot Symfony or open a database when a focused unit test proves the
  same behavior; add integration coverage when wiring or persistence itself is
  the behavior under test.

These rules complement the red-green-refactor workflow in the `tdd` skill.
