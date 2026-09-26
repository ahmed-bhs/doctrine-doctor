# Subagent Briefs

These briefs define focused review roles. Use one when a change benefits from a
second pass; do not split small edits just to create parallel work.

| Brief | Use for |
|-------|---------|
| [Runtime Reviewer](runtime-reviewer) | Profiler and request-SQL changes |
| [Static Reviewer](static-reviewer) | CI, source, mapping, and database audits |
| [Architecture Reviewer](architecture-reviewer) | Seams, dependency direction, and ADRs |
| [Doctrine Architect](doctrine-architect) | Mapping and repository design |
| [Doctrine Performance Reviewer](doctrine-performance-reviewer) | Read-only Doctrine performance review |

Each reviewer reads the project memory and rules first, then reports evidence,
risks, and the smallest useful next step.
