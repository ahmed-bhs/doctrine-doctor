---
name: tdd
description: Test-driven development. Use when the user wants to build features or fix bugs test-first, mentions "red-green-refactor", or wants integration tests.
---

# Test-Driven Development

TDD is the red → green loop. This skill is the reference that makes that loop produce tests worth keeping: what a good test is, where tests go, the anti-patterns, and the rules of the loop. Every section applies on every cycle: consult them before and during the loop, not after.

When exploring the codebase, read `CONTEXT.md` (if it exists) so test names and interface vocabulary match the project's domain language, and respect ADRs in the area you're touching.
Also read the project's [testing rules](../../../docs/rules/testing.md): they
define the test pyramid, behavior seams, and the default no-mocks policy.

## Project TDD defaults

- Work in vertical slices: write one behavior-level failing test, implement the
  smallest change, make it pass, then refactor with the suite green.
- Start with a unit test for pure analyzer, parser, value-object, or factory
  behavior. Add integration coverage only when Symfony wiring, Doctrine
  metadata, template rendering, or a database is part of the behavior.
- For analyzers, use the matrix: finding, no finding, threshold or malformed
  input, and the regression that motivated the change.
- Use real project objects, fixtures, in-memory implementations, or small fakes.
  Mocks are an exception for true external boundaries, never the default for
  project classes or internal collaborators.
- Keep the test suite pyramid-shaped: many fast unit tests, fewer integration
  tests, and a small number of platform or end-to-end tests.

## What a good test is

Tests verify behavior through public interfaces, not implementation details. Code can change entirely; tests shouldn't. A good test reads like a specification: "user can checkout with valid cart" tells you exactly what capability exists, and it survives refactors because it doesn't care about internal structure.

See [tests.md](tests.md) for examples and [mocking.md](mocking.md) for the
narrow exception when a true external boundary must be isolated.

## Seams: where tests go

A **seam** is the public boundary you test at: the interface where you observe behavior without reaching inside. Tests live at seams, never against internals.

**Test at explicit seams.** Before writing a test, state the public seam under
test and confirm it only when the choice is genuinely ambiguous. Do not block a
clear, low-risk change on a ceremony. You can't test everything, so name the
critical path and complex behavior the test protects.

Ask: "What's the public interface, and which seams should we test?"

When the shape of that interface is itself in question (how deep the module is, where the seam belongs, what the interface should expose), call the Skill tool with "codebase-design" for the vocabulary. It is the shared source of the module, interface, depth, seam, adapter, leverage and locality terms, and it is a reference to consult, not a session to run.

## Anti-patterns

- **Implementation-coupled**: mocks internal collaborators, tests private methods, or verifies through a side channel (querying the database instead of using the interface). The tell: the test breaks when you refactor but behavior hasn't changed.
- **Tautological**: the assertion recomputes the expected value the way the code does (`expect(add(a, b)).toBe(a + b)`, a snapshot derived by hand the same way, a constant asserted equal to itself), so it passes by construction and can never disagree with the code. Expected values must come from an independent source of truth: a known-good literal, a worked example, the spec.
- **Horizontal slicing**: writing all tests first, then all implementation. Bulk tests verify _imagined_ behavior: you test the _shape_ of things rather than user-facing behavior, the tests go insensitive to real changes, and you commit to test structure before understanding the implementation. Work in **vertical slices** instead: one test → one implementation → repeat, each test a **tracer bullet** that responds to what the last cycle taught you.

## Rules of the loop

- **Red before green.** Write the failing test first, then only enough code to pass it. Don't anticipate future tests or add speculative features.
- **One slice at a time.** One seam, one test, one minimal implementation per cycle.
- **Refactoring is not part of the loop.** It belongs to the review stage (see the `code-review` skill), not the red → green implementation cycle.
