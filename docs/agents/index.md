# Subagent Briefs

These briefs define focused review roles for Claude Code. Use one when a change
benefits from a second pass; do not split small edits just to create parallel
work.

| Brief | Use for |
|-------|---------|
| `.claude/agents/runtime-reviewer.md` | Profiler and request-SQL changes |
| `.claude/agents/static-reviewer.md` | CI, source, mapping, and database audits |
| `.claude/agents/doctrine-architect.md` | Mapping and repository design |
| `.claude/agents/doctrine-performance-reviewer.md` | Read-only Doctrine performance review |

Each reviewer reads the project memory and rules first, then reports evidence,
risks, and the smallest useful next step.

## Where things belong

| Location | Role | Loaded when |
|----------|------|-------------|
| `AGENTS.md` | Codex routing and project invariants | Every Codex task |
| `CLAUDE.md` | Claude project memory entry point | Every Claude session |
| `.agents/skills/` | Portable reusable task workflows | On demand |
| `.claude/skills/` | Claude entry points to those workflows | On demand |
| `.claude/agents/` | Isolated specialist reviews | When delegated |
| `docs/guides/` | Human and agent procedures | When relevant |
| `docs/rules/` | Stable project constraints | When relevant |
| `docs/adr/` | Hard-to-reverse decisions | When a decision is involved |
| `.claude/hooks/` | Deterministic Claude lifecycle scripts | Session/tool lifecycle |
| `.githooks/` | Optional local Git checks | Commit and push |

The project Claude hook only loads the runtime context at session start. Heavy
quality checks remain explicit or run in CI.
