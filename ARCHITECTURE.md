# Architecture

This file is the quick entry point for contributors and agents. The canonical
architecture reference is [the advanced architecture guide](docs/advanced/architecture.md).
It contains the detailed diagrams, lifecycle, layers, and interfaces.

## Stable map

- **Profiler runtime**: request SQL is collected and checked by `AnalyzerInterface` implementations.
- **CI static**: source, mappings, and metadata are checked by `StaticAnalyzerInterface` implementations.
- **CI database audit**: live database checks use `DatabaseAuditAnalyzerInterface` and require `--with-database`.
- **Domain**: issues, suggestions, value objects, collections, and analyzer policy.
- **Adapters**: Symfony, Doctrine, database, template, and command integration.

Dependencies point toward stable contracts. Commands and collectors orchestrate;
analyzers detect. Use ADRs for structural decisions and the execution inventory
for the current analyzer classification.

This file is intentionally a map, not a second architecture specification.
