---
name: doctrine-analyzer
description: Add or change a Doctrine Doctor analyzer, including its execution mode, tests, configuration, and documentation.
---

# Doctrine Analyzer Skill

1. Read [CONTEXT.md](../../CONTEXT.md) and [the analyzer guide](../../docs/contributing/creating-analyzers.md).
2. Choose the narrowest analyzer contract from the evidence required.
3. Keep detection behind the analyzer interface and inject factories, parsers,
   and adapters.
4. Add a focused positive case, a no-finding case, and a regression case.
5. Run the focused test, `composer phpstan`, `composer ecs`, and the relevant
   `doctrine:doctor:analyze` command.
6. Update user documentation when the analyzer changes user-visible behavior.

Done means the contract is explicit, tests cover the behavior, and the relevant
quality checks pass.
