# ADR 0001: Keep the agent harness small and routed

- Status: Accepted
- Date: 2026-09-26

## Context

Agents working on Doctrine Doctor need project vocabulary, architecture
constraints, repeatable workflows, and quality commands. Putting all of that in
one instruction file makes it expensive to read and easy to contradict. Adding
many overlapping files creates the same problem for maintainers.

## Decision

Use a small, layered harness:

- `AGENTS.md` is the entry point and routes agents to the right material.
- `CONTEXT.md` is the domain glossary and contains no implementation recipe.
- `docs/adr/` records decisions that are difficult to reverse or surprising without context.
- `docs/guides/` contains repeatable workflows.
- `.claude/skills/` contains short, task-triggered procedures that point to the guides.
- `.claude/agents/` contains read-only or narrowly scoped Claude subagents.
- `.githooks/` contains optional, fast local checks; CI remains authoritative.
- `CLAUDE.md` remains a compatibility document and should point to `AGENTS.md` rather than duplicate it.

The harness follows progressive disclosure: always-loaded instructions stay
short, while task-specific reference is reached through explicit pointers.

## Consequences

Agents get a predictable entry point without loading every document for every
task. Humans have a clear place for vocabulary, decisions, procedures, and
automation. The project must keep pointers accurate and avoid copying the same
rule into multiple files.

## Alternatives considered

- A full root `ARCHITECTURE.md`: rejected because `docs/advanced/architecture.md`
  already provides the detailed reference. A short root map is kept only for
  discoverability and points to that canonical guide.
- A large `rules/` tree: rejected because PHPStan, ECS, Deptrac, PHPUnit, and
  CI already enforce executable rules.
- Mandatory heavy hooks: rejected because slow checks belong in CI and should
  not block small local commits.
