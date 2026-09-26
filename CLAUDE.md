# Doctrine Doctor

Doctrine ORM profiler and CI analysis bundle. Request-SQL analyzers run in Symfony Web Profiler; source/mapping checks run with `doctrine:doctor:analyze`.
100 analyzer services across Performance, Security, Integrity, and Configuration.

Agent entry point: read [AGENTS.md](AGENTS.md) first. It routes to the project
context, architecture guide, ADRs, guides, and skills without duplicating them.

- Namespace: `AhmedBhs\DoctrineDoctor`
- PHP 8.4+ / Symfony 6-8 / Doctrine ORM 3-4

## Commands

```bash
# Tests
make test                    # PHPUnit
composer test:unit           # unit tests only
make coverage                # HTML report in coverage/

# Quality
composer ecs                 # code style check
composer ecs:fix             # auto-fix code style
make phpstan                 # PHPStan level 8 (bleeding edge)
make rector                  # Rector dry-run
composer phpmd               # mess detection
composer deptrac             # architectural constraints
composer lint                # syntax check (parallel-lint)
composer markdown-lint       # docs markdown lint
php bin/console doctrine:doctor:analyze # source and mapping checks for CI
php bin/console doctrine:doctor:analyze --with-database # include live database audits

# Combined
composer check               # lint + test + ecs + phpstan + phpmd + rector + deptrac
composer cs:fix              # ecs:fix + rector:fix
make qa                      # alias for check
```

## Code conventions

- `declare(strict_types=1)` required
- License header on every file (enforced by ECS)
- PSR-12, Yoda comparisons (`null === $value`)
- Trailing commas in multiline arrays, arguments, parameters
- `self::` in PHPUnit (not `static::`)
- Test names in snake_case, prefixed with `it_` (e.g. `it_detects_n_plus_one_queries`)
- PHPStan level 8, bleeding edge, `checkUninitializedProperties: true`
- Imports ordered: const, class, function (alphabetical)
- No `final` on extension points (analyzers, issues); internal helpers, parsers, strategies, collections, value objects, DTOs and test classes may be `final`
- Constructor promotion with `readonly`
- Concatenation with spaces (`$a . $b`, not `$a.$b`)

## Architecture (Deptrac)

```text
Domain        : ValueObject, DTO, Issue, Suggestion, Collection
Application   : Analyzer, Detector, Helper
Infrastructure: Factory, Strategy, Collector, Template, Generator, Utils, Service
Presentation  : Command
Configuration : DependencyInjection, Bundle
```

Dependencies only point inward: Domain imports nothing from Application/Infrastructure.

### Analyzer pattern (Strategy)

Execution has separate runtime and CI paths:

- **`AnalyzerInterface`**: query-dependent analyzers that consume this request's `QueryDataCollection`; these run in the profiler.
- **`StaticAnalyzerInterface`**: source-code or mapping checks that do not depend on request SQL; these run in CI.
- **`MetadataAnalyzerInterface`** (extends `StaticAnalyzerInterface`): metadata checks using `MetadataAnalyzerTrait` to bridge `analyze()` -> `analyzeMetadata()`.
- **`DatabaseAuditAnalyzerInterface`** (extends `MetadataAnalyzerInterface`): CI checks that inspect live database settings or schema; the command includes them only with `--with-database`.

```php
// Query analyzers
public function analyze(QueryDataCollection $queryDataCollection): IssueCollection;

// Metadata analyzers
public function analyzeMetadata(): IssueCollection; // via MetadataAnalyzerInterface + trait
```

- Auto-discovered via the compatibility tag `doctrine_doctor.analyzer`; the compiler assigns runtime and static tags from the analyzer interfaces.
- The data collector receives only `doctrine_doctor.runtime_analyzer`; the CI command receives `doctrine_doctor.static_analyzer`.
- Located in `src/Analyzer/{Performance,Security,Integrity,Configuration}/`
- Receive a decorated `EntityManager` (vendor entity filtering is transparent)
- Create Issues directly (`new IntegrityIssue(...)`, `new PerformanceIssue(...)`)
- Use config objects as default parameters instead of boolean flags (PHPMD compliance)
- Always return `IssueCollection::fromGenerator(fn() => yield ...)` for lazy evaluation

### Typical analyzer query pipeline

```php
$selects = $queryDataCollection->onlySelects();
$grouped = $selects->groupByNormalizedSql();
foreach ($grouped as $group) {
    if ($group->count() >= $this->threshold) {
        yield new PerformanceIssue(new IssueData(...));
    }
}
```

Key `QueryDataCollection` methods: `onlySelects()`, `onlyInserts()`, `groupByPattern()`, `groupByNormalizedSql()`, `excludePaths()`.

### Suggestion templates

- PHP files in `src/Template/Suggestions/{Category}/`
- Rendered via `PhpTemplateRenderer` (implements `TemplateRendererInterface`)
- Excluded from Deptrac and PHPMD; ECS and PHPStan still check them
- Must handle missing context keys gracefully
- Validated by `bin/validate-suggestion-templates.php` in CI:
  - Must use `ob_start()` / `ob_get_clean()`
  - Must return `['code' => $html, 'description' => $text]`
  - Must use `htmlspecialchars()` for output
  - No markdown syntax, no `eval()`

## Adding an analyzer

1. Create class in `src/Analyzer/{Category}/`
2. Implement `AnalyzerInterface` for request-SQL checks, `StaticAnalyzerInterface` for source/mapping checks, or `DatabaseAuditAnalyzerInterface` for live database audits
3. Create suggestion template in `src/Template/Suggestions/{Category}/`
4. Register in `config/services.yaml` with tag `doctrine_doctor.analyzer`
5. Add tests in `tests/Unit/Analyzer/` or `tests/Analyzer/`
6. See `CONTRIBUTING_ANALYZER.md` for the full guide

Service registration — autowiring works if the analyzer only needs standard interfaces. For custom thresholds or services, declare arguments explicitly:

```yaml
AhmedBhs\DoctrineDoctor\Analyzer\Performance\YourAnalyzer:
    arguments:
        $threshold: '%doctrine_doctor.analyzers.your_analyzer.threshold%'
    tags:
        - { name: doctrine_doctor.analyzer }
```

## Domain model

### ValueObjects

- `Severity`: 3-level enum (info, warning, critical) with `getPriority()`, `isHigherThan()`
- `SuggestionType`: performance(), security(), integrity(), configuration(), bestPractice(), refactoring()
- `QueryExecutionTime`: factory methods `fromSeconds()`, `fromMilliseconds()`
- `IssueType`: enum mapping all issue types

### Issues

- `AbstractIssue` base class, concrete types: `PerformanceIssue`, `SecurityIssue`, `IntegrityIssue`, `ConfigurationIssue`
- Built from `IssueData` DTO (immutable)
- Track duplicates via `getDuplicatedIssues()` / `addDuplicatedIssue()`
- Constructor accepts `string|Severity` (legacy string values auto-converted)

### Collections

- `IssueCollection`: `fromGenerator()` (lazy), `fromArray()` (eager)
- Fluent API: `.filtering()` -> `IssueFilter`, `.sorting()` -> `IssueSorter`, `.statistics()` -> `IssueStatistics`
- `QueryDataCollection`: typed collection with filtering/grouping methods

## Tests

- PHPUnit 10 with attributes (`#[Test]`, `#[CoversClass]`)
- Descriptive snake_case test names
- No common base class — all extend `TestCase` directly
- `QueryDataCollection::fromArray([...])` for query injection
- `QueryDataCollection::empty()` for configuration analyzers (no queries needed)
- Fixtures in `tests/Fixtures/` (excluded from Rector and PHPMD)
- Test entities in `tests/Fixtures/Entity/`

### Test helpers

- **`QueryDataBuilder`** (`tests/Support/QueryDataBuilder.php`): fluent builder with `addQuery()`, `addSlowQuery()`, `addFrequentQuery()`, `addQueryWithParams()`, `addQueryWithRowCount()`, `addQueryWithBacktrace()`
- **`PlatformAnalyzerTestHelper`** (`tests/Integration/PlatformAnalyzerTestHelper.php`): `createSQLiteConnection()`, `createTestEntityManager()`, `createIssueFactory()`, `createSuggestionFactory()`

### Assertion patterns

```php
self::assertCount(1, $issues);
$issue = $issues->first();
self::assertInstanceOf(PerformanceIssue::class, $issue);
self::assertStringContainsString('expected text', $issue->getDescription());
```

## Excluded paths

| Path | Excluded from |
|---|---|
| `src/Template/Suggestions/` | Deptrac, PHPMD |
| `tests/Fixtures/` | Rector, PHPMD |
| `*/DependencyInjection/Configuration.php` | PHPMD |
| `*/DependencyInjection/*Extension.php` | PHPMD |

## CI (GitHub Actions)

Jobs: Lint, ECS, PHPMD, PHPStan, Tests (xdebug coverage), Deptrac, Markdown Lint, Validate Templates.
Triggers: push to main/develop, all PRs. PHP 8.4, extensions: mbstring, intl, pdo, pdo_sqlite.

## Worker mode

Compatible with FrankenPHP/RoadRunner/Swoole via `WorkerModeResetSubscriber` and `kernel.reset` tag on the DataCollector.
`ServiceHolder` contains static state reset between requests.

## Cross-database support

`DatabasePlatformDetector` abstracts platform differences (MySQL, PostgreSQL, SQLite).
Platform-specific logic isolated in `src/Infrastructure/Strategy/`. Analyzers use `PlatformAnalysisStrategyFactory` for platform-aware analysis.
EXPLAIN output parsing differs per platform — always go through the abstraction.

## Key file paths

| Purpose | Path |
|---|---|
| Analyzer interfaces | `src/Analyzer/AnalyzerInterface.php`, `src/Analyzer/StaticAnalyzerInterface.php`, `src/Analyzer/MetadataAnalyzerInterface.php`, `src/Analyzer/DatabaseAuditAnalyzerInterface.php` |
| Data collector (orchestrator) | `src/Collector/DoctrineDoctorDataCollector.php` |
| Service wiring | `config/services.yaml` |
| Bundle config options | `src/DependencyInjection/Configuration.php` |
| Suggestion templates | `src/Template/Suggestions/{Category}/` |
| Template validator | `bin/validate-suggestion-templates.php` |
| Test builder | `tests/Support/QueryDataBuilder.php` |
| Test entities | `tests/Fixtures/Entity/` |
| Analyzer contribution guide | `CONTRIBUTING_ANALYZER.md` |
