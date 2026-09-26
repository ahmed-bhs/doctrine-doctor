---
name: create-analyzer
description: Create a new Doctrine Doctor analyzer with the correct execution contract, registration, tests, suggestion, and documentation.
---

# Create Analyzer Skill

1. Read [MEMORY.md](../../../MEMORY.md), [CONTEXT.md](../../../CONTEXT.md),
   [ARCHITECTURE.md](../../../ARCHITECTURE.md), the [analyzer rules](../../../docs/rules/analyzers.md),
   and [the analyzer guide](../../../docs/contributing/creating-analyzers.md).
2. Read [parsing-strategy](../parsing-strategy/SKILL.md) before choosing string
   matching, regex, tokenization, or a parser. Read [object-design](../object-design/SKILL.md)
   when introducing a new class or abstraction.
3. Find the nearest existing analyzer and confirm the evidence it needs:
   request SQL, source/mapping metadata, or live database state.
4. Choose the narrowest contract and record the execution path: profiler,
   static CI, or opt-in database audit.
5. Keep detection behind the contract, inject adapters, and preserve lazy issue
   evaluation. Register the service with `doctrine_doctor.analyzer`.
6. Add a no-finding case, a finding case, the threshold or edge case, and a
   regression case. Test through the highest useful public seam.
7. Add or update the suggestion template, analyzer catalog, and user-facing
   documentation when behavior is visible to users.
8. Run the focused test, `composer phpstan`, `composer ecs`, and the relevant
   `doctrine:doctor:analyze` command. Run the full PR checks before review.

Done means the analyzer has one clear responsibility, the contract and
execution path are explicit, tests cover behavior and regression, and the
catalog and documentation agree with the code.
