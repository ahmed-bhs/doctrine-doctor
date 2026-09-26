# Project Memory

This file records durable facts that help contributors and agents make consistent decisions. Keep it short, factual, and stable. Put terminology in `CONTEXT.md`, decisions in `docs/adr/`, procedures in `docs/guides/`, and Claude workflows in `.claude/skills/`.

## Product shape

Doctrine Doctor has two execution paths:

- The Symfony Web Profiler observes SQL from a real request.
- `doctrine:doctor:analyze` checks source code, mappings, and opt-in database state in CI.

The execution boundary is part of the public design. A check must declare the narrowest contract that matches its evidence.

## Design constraints

- Domain types stay independent from Symfony and Doctrine infrastructure.
- Application modules depend on interfaces; adapters own framework and database details.
- Commands, collectors, and presenters stay thin.
- New behavior needs a focused test and a regression test when it fixes a failure.
- `docs/advanced/architecture.md` is the detailed architecture reference.
- CI is authoritative; local hooks are optional feedback.

## Change record

- Runtime profiling and static CI analysis are intentionally separate paths.
- Database audits are opt-in through `--with-database` because they need a live database.
