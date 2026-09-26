# Working in Doctrine Doctor

Doctrine Doctor is a Symfony bundle that checks Doctrine usage in two places:
the Web Profiler observes SQL from a real request, and `doctrine:doctor:analyze`
checks source code and mappings in CI.

## Before changing code

1. Read [CONTEXT.md](CONTEXT.md) for the project's vocabulary.
2. Read [the architecture guide](docs/advanced/architecture.md) before changing module seams, layers, or Deptrac rules.
3. Read the relevant ADR in [docs/adr](docs/adr/) when changing an established design decision.
4. For analyzer work, follow [the analyzer guide](docs/contributing/creating-analyzers.md).

## Execution contracts

- `AnalyzerInterface`: request-dependent checks run in the profiler.
- `StaticAnalyzerInterface`: source and mapping checks run in CI.
- `DatabaseAuditAnalyzerInterface`: live database checks run in CI only with `--with-database`.
- `MetadataAnalyzerInterface`: metadata-oriented static checks; it extends `StaticAnalyzerInterface`.

Choose the narrowest contract that fits the data the analyzer needs. Keep the
interface small and put orchestration behind an application module rather than
making commands or collectors know analyzer internals.

## Required checks

Run the smallest relevant checks while iterating. Before opening a PR, run:

```bash
composer lint
composer ecs
composer phpstan
composer phpmd
composer rector
composer deptrac
composer test
composer markdown-lint
```

For a static analyzer change, also run:

```bash
php bin/console doctrine:doctor:analyze --fail-on=warning
```

Describe any check that cannot run and why. Do not weaken a quality gate to
hide a finding; fix the design or document an explicit, reviewed exception.

## Change boundaries

- Keep domain types independent from Symfony and Doctrine infrastructure.
- Depend on interfaces at seams and inject adapters.
- Prefer composition, immutable value objects, and explicit dependencies.
- Add a meaningful test for every behavior change.
- Document decisions and trade-offs in an ADR; do not use ADRs for routine implementation notes.
- Keep documentation English, concise, and user-oriented.

The repository may contain local drafts unrelated to the current change. Leave
them untouched unless the task explicitly includes them.
