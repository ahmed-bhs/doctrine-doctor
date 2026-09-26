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
