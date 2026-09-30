---
name: symfony-context
description: Establish Doctrine Doctor's runtime versions, execution paths, and quality commands before a Symfony or Doctrine change.
---

# Symfony Context Skill

Run `./bin/doctrine-doctor-context --format=markdown` when a task changes code,
dependencies, execution paths, or environment assumptions. For a documentation
typo or a one-file wording change, skip it. Use `--format=json` for tooling and
`--format=env` for scripts.

Then read [MEMORY.md](../../../MEMORY.md), [CONTEXT.md](../../../CONTEXT.md), and the
relevant rule or skill. Treat the detected context as evidence; confirm details
in `composer.json` when a dependency or version decision matters.

Done means the task uses the project's actual commands and distinguishes the
profiler runtime path from the CI static and opt-in database paths.
