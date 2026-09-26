# Working in Doctrine Doctor

`AGENTS.md` is the project router. Keep durable facts in [MEMORY.md](MEMORY.md),
domain vocabulary in [CONTEXT.md](CONTEXT.md), decisions in [docs/adr](docs/adr/),
procedures in [docs/guides](docs/guides/), and reusable workflows in [skills](skills/).

## Before changing code

1. Run `./bin/doctrine-doctor-context --format=markdown` to establish the real
   PHP, Symfony, Doctrine, test, and execution-path context.
2. Read [MEMORY.md](MEMORY.md), [CONTEXT.md](CONTEXT.md), and the
   [architecture guide](docs/advanced/architecture.md).
3. Read the relevant [rules](docs/rules/), ADR, and skill before changing a
   boundary or analyzer.
4. Use a focused [subagent brief](agents/) only when a second pass adds value.

## Choose the smallest workflow

| Need | Start here |
|------|------------|
| Clarify a requested change | `.agents/skills/to-spec` |
| Shape a module or seam | `skills/architecture-design` |
| Change an analyzer | `skills/doctrine-analyzer` |
| Change Symfony integration | `skills/symfony-quality` |
| Implement test-first | `.agents/skills/tdd` |
| Review a branch | `.agents/skills/code-review` |
| Review Doctrine design | `agents/doctrine-architect.md` |
| Review Doctrine performance | `agents/doctrine-performance-reviewer.md` |

## Non-negotiable project rules

- Keep domain policy independent from Symfony and Doctrine adapters.
- Choose the narrowest execution contract: profiler runtime, CI static, or
  opt-in database audit.
- Keep commands, collectors, and presenters thin; put policy behind a small
  interface at an explicit seam.
- Add focused behavior and regression coverage for changes.
- Treat PHPStan and Deptrac findings as design feedback.
- Keep documentation concise and in English. Record only hard-to-reverse,
  surprising trade-offs as ADRs.

Before opening a PR, follow [Quality Checks](docs/guides/quality-checks.md).
Leave unrelated local drafts untouched.

## Compatibility

Project workflow skills from Matt Pocock's collection are pinned in
`skills-lock.json` under `.agents/skills/`. Update them deliberately with
`npx skills update`, then review the diff.
