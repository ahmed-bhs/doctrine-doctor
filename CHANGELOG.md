# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `AutoGenerateProxyClassesAnalyzer` and `DoctrineCacheAnalyzer` both reported proxy auto-generation under the identical title `Proxy Auto-Generation Enabled in Production`, at critical severity. Issues are not deduplicated by title, so in production with auto-generation on, the panel showed the same critical alert twice with nothing to tell them apart. The titles now name their source — `(config file)` and `(runtime)` — and the runtime message states that the value comes from the EntityManager and therefore includes overrides applied after the configuration files are loaded. Detection is unchanged: the two were kept separate rather than merged because they read different sources and neither covers the other. `AutoGenerateProxyClassesAnalyzer` parses the production YAML and has no environment guard, so it warns from the dev profiler before anything is deployed; `DoctrineCacheAnalyzer` reads `getAutoGenerateProxyClasses()` behind a `prod` guard and is the only one that sees a runtime override the YAML does not show. Each of the two divergent cases is detected by exactly one of them, verified across the full matrix of config value, runtime value and environment.

### Added

- Three Integrity analyzers covering mappings that Doctrine itself does not reject, drawn from the ORM 3.6 and DBAL 4.4 deprecation cycle. `DiscriminatorColumnAnalyzer` reports a discriminator column whose length cannot hold the longest key of its own `DiscriminatorMap` — the value is truncated on write and the row can no longer be resolved back to its class — and, separately, a Single Table Inheritance hierarchy with no index on the discriminator column, where every subclass query appends a filter on it and scans the whole table. `NullablePrimaryKeyAnalyzer` reports primary key columns mapped as `nullable`, deprecated in ORM 3.6 (doctrine/orm#12126) and rejected in 4.0. `StringDefaultExpressionAnalyzer` reports raw SQL string defaults on temporal columns, deprecated in ORM 3.6 (doctrine/orm#12252) in favour of the `DefaultExpression` value objects added in DBAL 4.4 (doctrine/dbal#7195); it mirrors `SchemaTool` exactly, matching only temporal types whose default equals the current platform's own current-date/time SQL, so a string column holding the same literal is left alone.
- Detection of an entity missing from its root's `DiscriminatorMap` was considered and deliberately left out: `ClassMetadataFactory` throws `mappedClassNotPartOfDiscriminatorMap` on that mapping, so `getAllMetadata()` never returns and no analyzer can observe it. Abstract classes escape the throw but legitimately carry no discriminator value. The same applies to invalid enum entries, which `invalidEntriesInDiscriminatorMap` already rejects.
- The three analyzers are not gated by ORM version. `doctrine/doctrine-bundle` 3.x requires DBAL `^4.0`, which floors the ORM at 3.x, so an ORM 2.20 user cannot install this bundle and a version guard would be unreachable code.
- A `Rector` job in CI. Rector was in `composer check` but in none of the workflow jobs, which is how 106 files accumulated without a single red build. The gate is now enforced where it is visible.

### Changed

- `doctrine/orm` in `require-dev` is constrained to `^3.0` instead of `^3.0|^4.0`. No 4.x has been released — Packagist lists only the `4.0.x-dev` branch — so the upper bound allowed the development branch of an unreleased major to be resolved, whose BC breaks are by definition unknown and untested here. ORM 3.6 already deprecates mappings that 4.0 will reject, which is exactly the window in which an accidental resolution would fail in confusing ways. The constraint was widened in ec60e80 as part of a broad modernization commit rather than as a deliberate targeting of ORM 4, and nothing in the CI matrix, the documentation or the code refers to it. Runtime dependencies are unaffected: the bundle requires `doctrine/doctrine-bundle` rather than the ORM directly, so this changes only what the bundle's own test suite resolves against.
- Rector's PHP 8.4 modernization is applied across the codebase, 106 files. `composer check` was red on `main` even on an untouched checkout, because Rector reported those files while none of the CI jobs ran it — so the combined gate had drifted out of reach locally without anything failing publicly. By volume the rules were `NullToStrictStringFuncCallArgRector` (43 files), `AddOverrideAttributeToOverriddenMethodsRector` (26), `NewMethodCallWithoutParenthesesRector` (12), `StringClassNameToClassConstantRector` (10), and fourteen others with five or fewer occurrences. Two needed correcting afterwards: `NewInInitializerRector` promoted four lazily-initialized dependencies into constructor defaults but kept the parameter nullable, turning four non-nullable properties into nullable ones — PHPStan caught it as six `cannot call method on X|null` errors, and the nullable markers were removed since the default is always an instance; ECS then reformatted the constructors Rector had collapsed onto single lines. The suggestion templates were validated separately, since that directory is excluded from ECS, PHPStan, Deptrac and PHPMD and is policed only by `bin/validate-suggestion-templates.php` — all 112 pass, which matters because `NullToStrictStringFuncCallArgRector` inserts casts of exactly the shape that validator rejects when they are not a known escaper. Deprecation notices in the test suite dropped from 10 to 8.

## [2.10.2] - 2026-08-24

### Fixed

- Suggestion banners ignored the severity the analyzer reported. `SuggestionMetadata` carries the `Severity`, but `render()` only ever receives the context array, so every template hardcoded its own `alert-danger` / `alert-warning` / `alert-info` class. `FlushInLoopAnalyzer` shows the cost most clearly: it picks critical, warning or info from the flush count, while the template always drew the critical red banner, so three flushes in a loop looked exactly as severe as sixty. `SuggestionFactory` now seeds the context with the metadata severity, leaving an explicit value untouched, and a `severityAlertClass()` helper maps it to the alert class. Applied to the twelve templates whose banner contradicted the severity they were rendered with.
- Templates did not degrade when a context key was missing, though that is a documented requirement. 21 destructured the context array directly: `Performance/index` raised a `TypeError` as soon as any key was absent, and others rendered placeholder text such as `Rename table from '' to ''`. Every context read now has an explicit default and a cast, and `Integrity/code_suggestion` no longer returns an empty description.
- Values routed through a string function skipped escaping. Bare variables went through the template's `$e()`, but `ucfirst($fieldName)`, `strtolower()` and `substr()` were echoed raw in 20 templates — 88 occurrences. These values come from Doctrine metadata rather than request input, so this was not exploitable, but the escaping was structurally absent rather than deliberately skipped.
- Three license headers had been mangled into `<ul><li>` markup by a markdown conversion, 46 templates carried emoji in the profiler output, and 54 opened documentation links with `target="_blank"` and no `rel`.

### Changed

- The shared suggestion chrome — header block, severity banner, code sample and documentation link — was rewritten by hand in every template, so the markup drifted and each fix had to be repeated a hundred times. Four helpers in `src/Template/helpers.php` now own it, and the `naming_convention` cluster is rewritten on top of them as the worked example, from 165 lines to 53. Visible text is escaped with `ENT_NOQUOTES` so apostrophes stay readable in the panel, while attributes keep full quote escaping. Rendered output of all 113 templates was snapshotted before the change and compared after: no drift in rendered text or descriptions, and no CSS class or HTML tag lost.
- `src/Template/Suggestions/` is no longer excluded from ECS and PHPStan. The exclusion was documented as covering "template files with PHP interpolation", but the templates pass both, level 8 included. Two test classes and five validator checks now guard the directory: every template renders with an empty context without raising, the banner follows the severity at all three levels, the layout helpers escape markup, and the validator rejects emoji, missing `rel` attributes, context destructuring without defaults, and echoing the result of a function that is not a known escaper.

### Removed

- `EXAMPLE_SECURE_TEMPLATE` shipped as documentation for the `SafeContext` auto-escaping API but was never referenced by any analyzer, factory or test, while still being picked up as a renderable template. `docs/advanced/template-security.md` covers the same ground, including the same example.

## [2.10.1] - 2026-08-19

### Changed

- The `setMaxResults() with Collection Join` alert now carries the facts of the query that triggered it instead of a fixed block of theory: the LIMIT value and the number of fetch-joined tables, the row count when the profiler reports one, and an explicit note when a result set reached its LIMIT and was therefore very likely truncated in the middle of a root entity. When the query is offset past the first page, the alert also warns that a batch loop bounded by a root entity count can exit early and never read the remaining entities — whole entities lost, not just collection items. Both remediations are given, paginating on identifiers and `Paginator`, with the trade-off between them. Every enrichment degrades to the base message when no row count is available, so the alert stays complete either way. Detection logic is unchanged.

### Fixed

- CI could not install dependencies on any branch. `composer.lock` is not tracked, so every run resolves afresh, and CVE-2026-53965 (reported 2026-08-14) covers `mcp/sdk` `>=0.5.0,<0.7.1`. `symfony/ai-symfony-mate-extension` was pinned to `^0.10`, whose `ai-mate` v0.10 requires `mcp/sdk ^0.6` exclusively, leaving no resolvable set. The constraint now allows `^0.11|^0.12`, which accept `mcp/sdk ^0.6|^0.7` and reach the patched v0.7.1.
- Five pre-existing single-letter closure parameters started failing the PHPMD job: `phpmd/phpmd` is required as `3.x-dev`, and PHPMD 3 applies `ShortVariable` to closure parameters where 2.15 did not. Renamed rather than widening the ruleset exceptions.
- A duplicate `case 'not_snake_case'` in the naming convention test's severity switch was unreachable dead code, reported by newer PHPStan as `switch.duplicateCase`. Removed; behaviour is unchanged.
- `hasOffset()` reported an offset for every `LIMIT` query. The SQL parser exposes an offset of `0` for a bare `LIMIT`, and the guard tested it with `'' !== (string) $offset`, which holds for `"0"`; the standalone-`OFFSET` regex fallback matched `OFFSET 0` for the same reason. It now compares against `0` and ignores a zero offset, so it answers whether the query is actually past the first page. `PaginationWithoutOrderByAnalyzer` derives its severity from this call, so a plain `LIMIT` without `ORDER BY` was reported as `LIMIT/OFFSET pagination` at `warning` instead of `LIMIT` at `info`; both cases are now reported correctly. `OrderByWithoutLimitAnalyzer` is unaffected. The method had no test coverage; a case per pagination form was added, including MySQL comma syntax and explicit zero offsets.

## [2.10.0] - 2026-07-30

### Added

- JSON report export from the profiler panel: an `Export JSON` button on the Doctrine Doctor panel heading downloads the current report (issues, stats, and grouped query timings) as a `.json` file. The payload is rendered into the panel and saved client-side via a `Blob`, so the feature needs no route and no routing configuration in the host application. Query entries are whitelisted field by field to drop the non-serializable `firstQuery` payload (backtrace, connection). Contributed by [@makomweb](https://github.com/makomweb) in [#120](https://github.com/ahmed-bhs/doctrine-doctor/pull/120).

### Security

- The inlined export payload is encoded with `JSON_HEX_TAG`, escaping `<` and `>`. Without it, a captured SQL string containing `</script>` could close the panel's `<script>` block early, and the `<!--<script` sequence could flip the HTML parser into double-escaped state where a later `</script>` no longer closes the element — both turning collected queries into executable markup in the profiler page.

## [2.9.4] - 2026-06-23

### Added

- `OrderByNullableLeadingColumnAnalyzer` (Performance/Integrity): flags `ORDER BY` on a nullable leading column combined with `LIMIT`. `NULL` placement (first or last) is platform- and configuration-dependent, so a query like `ORDER BY requested_at LIMIT 1` can silently skip rows whose sort column is `NULL` depending on the database engine. Info severity, flag-only, never auto-fixed since NULL-first/last is sometimes intentional. Configurable via `doctrine_doctor.analyzers.order_by_nullable_leading_column.enabled`.

### Fixed

- `FlushInLoopAnalyzer` / `FlushInLoopAnalyzerModern`: both were tagged `doctrine_doctor.analyzer` via the same glob registration, producing duplicate/conflicting findings for the same flush-in-loop pattern. `FlushInLoopAnalyzerModern` is now excluded from the tag glob while staying registered and autowireable.
- `GetReferenceAnalyzer`: no longer flags Doctrine's own optimistic-lock version-check re-reads (`SELECT version FROM table WHERE id = ?`) or PHP 8.4 native lazy-ghost object initialization as `find()`-instead-of-`getReference()` candidates. Added `ProxyFactory::createLazyInitializer` / `EntityPersister::loadById` to the lazy-loading backtrace markers alongside the legacy `Proxy::__load` / `__CG__::` ones.
- `DoctrineDoctorDataCollector`: expensive `runAnalysis()` work now runs in `lateCollect()` (after the HTTP response is sent) instead of `collect()` (before), on runtimes where this is safe. Auto-detected via `function_exists('fastcgi_finish_request')` so it works correctly on both php-fpm and persistent/worker-mode runtimes (FrankenPHP/RoadRunner/Swoole). No config flag needed.
- `CollectionJoinDetector::isForeignKeyInJoinedTable()`: compared SQL column names against `getIdentifierFieldNames()` (PHP field paths) instead of `getIdentifierColumnNames()` (actual DB column names). Broke for entities with embedded value-object identifiers, causing `ManyToOne` joins to fall through to an overly-broad table-wide fallback and get misclassified as collection joins.
- `UnusedEagerLoadAnalyzer`: carried its own copy of the now-fixed `CollectionJoinDetector` join-classification logic instead of reusing the shared helper, so it suffered the same value-object-identifier misclassification independently. Deduplicated to delegate to `CollectionJoinDetector`.

## [2.9.0] - 2026-05-23

### Added

- `DeepOffsetPaginationAnalyzer` (Performance): detects deep `OFFSET` pagination in executed SQL — `OFFSET >= 1000` is flagged as a warning, `OFFSET >= 10000` as critical. Cost grows linearly with page depth because the database must read and discard every skipped row. Suggests keyset (seek) pagination via `WHERE id > :lastId ORDER BY id LIMIT N` or an application-level cap on the maximum offset. Handles both `LIMIT n OFFSET m` and MySQL `LIMIT m, n` forms. Thresholds are configurable via `doctrine_doctor.analyzers.deep_offset_pagination.offset_warning_threshold` and `offset_critical_threshold`.
- `PaginationWithoutOrderByAnalyzer` (Performance): detects `LIMIT`/`OFFSET` pagination without an `ORDER BY` clause. SQL does not guarantee row order without an explicit `ORDER BY`, so identical executions can return different rows and consecutive pages can return duplicates or skip rows. Suggests adding a deterministic ORDER BY on a stable, indexed column (typically the primary key) and a PK tiebreaker when sorting by a non-unique column. Skips `LIMIT 1` single-row fetches where ordering is irrelevant.
- `FunctionOnPredicateColumnAnalyzer` (Performance): detects non-sargable functions wrapping a column in WHERE (`LOWER`, `UPPER`, `COALESCE`, `IFNULL`, `ISNULL`, `NULLIF`, `CAST`, `CONVERT`, `TRIM`, `LTRIM`, `RTRIM`, `SUBSTRING`, `SUBSTR`, `CONCAT`, `ABS`, `ROUND`, `FLOOR`, `CEIL`, `CEILING`). Wrapping a filtered column in a function defeats any standard index on that column and forces a full scan with per-row evaluation. Suggests rewriting the predicate so the column appears bare on one side, normalizing values at write time, or creating a functional/expression index. Date functions remain handled by `YearFunctionOptimizationAnalyzer`. Threshold configurable via `doctrine_doctor.analyzers.function_on_predicate_column.min_execution_time_ms` (default `10ms`).
- `NotInSubqueryAnalyzer` (Performance): detects `<column> NOT IN (SELECT ...)` patterns that silently return zero rows whenever the subquery yields any `NULL` value, due to SQL three-valued logic (`x NOT IN (a, b, NULL)` evaluates to `UNKNOWN`, never `TRUE`). A frequent source of bugs that pass in tests but break in production. Suggests `NOT EXISTS`, `LEFT JOIN ... IS NULL`, or an explicit `IS NOT NULL` filter inside the subquery.
- `ImplicitTypeConversionAnalyzer` (Performance): detects predicates likely to trigger implicit type conversion in the engine — numeric columns compared to quoted string literals (e.g. `user_id = '42'`) or date/time columns compared to bare integer literals. Implicit conversion typically disables index usage on the column. Heuristics rely on column-name suffixes (`_id`, `_count`, `_amount`, `_at`, `_date`, `_time`, ...) and skip placeholders (`?`, `:param`) since the bound type is invisible from SQL text alone. Suggests binding parameters with the correct PHP type or explicit DBAL `Types::*`.
- `SQLInjectionInRawQueriesAnalyzer`: extended to detect tautology variants (`OR 1=1`, `OR '1'='1'`, `OR TRUE`) and `UNION SELECT` injection patterns; the `LIMIT`/`OFFSET` vector is now also analyzed for concatenated injection attempts that previously slipped past the WHERE-only scan.
- DBAL-only support: the bundle now boots in pure Doctrine DBAL applications that do not have `doctrine/orm` installed. A new `RemoveOrmServicesPass` compiler pass removes ORM-dependent services (the `EntityManager` decorator, `EntityMetadataProvider`, and every analyzer with an `EntityManagerInterface` dependency) when `doctrine.orm.entity_manager` is absent, so the container compiles cleanly and only DBAL-native analyzers stay active.
- `NPlusOneSqlAnalyzer`: pure-SQL N+1 detection that groups identical `SELECT` patterns from `QueryDataCollection` and flags any group above the threshold (default 3), without needing ORM metadata. Required so DBAL-only applications get N+1 coverage equivalent to the existing ORM-aware analyzer.
- `MissingTransactionOnBatchAnalyzer`: walks the query timeline tracking transaction state via `START`/`BEGIN`/`SAVEPOINT` and `COMMIT`/`ROLLBACK`/`RELEASE SAVEPOINT` markers, and flags N >= threshold (default 10) `INSERT`/`UPDATE`/`DELETE` statements executed outside any transaction. Wrapping them in a single transaction collapses N fsyncs into one for a 10-100x speedup on durable storage.
- `YearFunctionOptimizationAnalyzer`: now also detects SQLite `strftime('%Y'|'%m'|'%d'|'%H'|'%M'|'%S', col)` and standard SQL `EXTRACT(part FROM col)` patterns in WHERE clauses, mapping them back to the existing `YEAR`/`MONTH`/... reasoning. Previously the analyzer was MySQL-only.
- Optional AI Mate / MCP integration for `doctrine_doctor`: added a profiler collector formatter, an MCP tool (`doctrine-doctor-issues`), and a sanitization layer for issue hints, traces, and SQL snippets so profiler findings can be consumed safely by AI agents when `symfony/ai-symfony-mate-extension` is installed in the host Symfony application.
- `QueryCachingOpportunityAnalyzer`: added `Doctrine 2LC Opportunity` detection for repeated fast `SELECT` entity-load patterns with varying parameter sets, plus a dedicated suggestion template and configuration thresholds for second-level cache candidates.

### Changed

- **BC**: `doctrine/orm` moved from `require` to `require-dev` + `suggest`. Installing `ahmed-bhs/doctrine-doctor` no longer pulls `doctrine/orm` transitively. Projects that depended on this transitive install must add `doctrine/orm` to their own `composer.json`. ORM-specific analyzers (N+1 via metadata, eager-loading mapping, partial objects, etc.) are silently disabled when `doctrine/orm` is absent.

### Fixed

- `RemoveOrmServicesPass`: when the host application does not configure `doctrine.orm.entity_manager`, also removes `PartialObjectAnalyzer`, whose `SELECT PARTIAL u.{...}` recommendation is unusable in pure DBAL and surfaced as a false positive on `SELECT *` queries.
- `TransactionBoundaryAnalyzer`: now recognizes Doctrine-quoted `"START TRANSACTION"` / `"COMMIT"` markers and treats `SAVEPOINT`/`RELEASE SAVEPOINT` as begin/commit so nested- and unclosed-transaction detection works in DBAL applications that call `$conn->beginTransaction()` directly.
- `EntityManagerClearAnalyzer`: now inspects query backtraces and only flags sequential `INSERT`/`UPDATE`/`DELETE` operations when at least one frame originates from the Doctrine ORM (`EntityManager`, `UnitOfWork`, `EntityRepository`, `ServiceEntityRepository`). Pure-DBAL batches no longer get a misleading `EntityManager::clear()` recommendation.
- `JoinTypeConsistencyAnalyzer` / `UnusedEagerLoadAnalyzer`: only fire on aggregations / many-JOIN queries when the FROM table is mapped in the ORM metadata. This silences `JOIN type may cause incorrect results` and `Unused eager loading` alerts on pure-DBAL queries over tables that are not ORM-managed.
- `SQLInjectionInRawQueriesAnalyzer` / `InjectionPatternDetector` / `QueryBuilderPatternDetector`: whitelist short clean `LIKE` / `WHERE` literal values (<=64 chars, no SQL meta-tokens) so hardcoded filter values like `WHERE country = 'FR'` or `LIKE '%abc%'` are no longer reported as SQL injection. Real concatenation attacks (literals containing `OR`/`AND`/`UNION`/`SELECT`/`DROP`/`--`/`;`, or suspiciously long values) still trigger the issue.
- `FindAllAnalyzer`: skips queries with `GROUP BY`/`HAVING` clauses and aggregate functions (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`) in the SELECT list - these are analytic queries by design, not unrestricted `findAll()` patterns.

- `DoctrineCacheAnalyzer`: the YAML-based scan now detects missing `metadata_cache_driver`, `query_cache_driver`, and `result_cache_driver` keys inside an existing `when@prod` section, not only the explicit `type: array` case. Previously, a `when@prod` block that omitted these keys entirely was silently ignored, causing the critical performance issue (entity metadata reparsed on every request, -50 to -80%) to go unreported in dev. The absence of `when@prod` altogether is still not flagged to avoid false positives on projects using split `config/packages/prod/` files.
- Added `missing_cache_production.php` suggestion template used for the new "not configured" issues, distinct from `array_cache_production.php` which covers the explicit array cache case.
- `OrderByWithoutLimitAnalyzer`: fast queries (below `min_execution_time_ms`) with a FK equality predicate in the WHERE clause (e.g. `WHERE deposit_request_id = ?`) are now silently skipped. These are aggregate-child collections whose size is bounded by the parent entity lifecycle, not by data volume. If the query ever degrades (execution time exceeds the threshold), the alert re-enables automatically.
- `FinalEntityAnalyzer`: added early-return guard when `enable_native_lazy_objects` (PHP 8.4 ghost objects) is active. Ghost objects decorate rather than subclass the entity, so `final` classes are safe — the previous behavior produced false-positive CRITICAL issues on PHP 8.4 projects.

### Security

- `QueryData` serialization: bound parameters flagged as sensitive (passwords, tokens, API keys, secrets, etc.) are now redacted before the `QueryData` DTO is serialized into the profiler payload, preventing credential leakage through the Symfony Web Profiler cache and any downstream MCP/AI integrations that consume profiler data.
- `PhpTemplateRenderer`: template names are now restricted to a strict allowlist regex, and the resolved template path is confined to the bundle's `Template/Suggestions/` directory via `realpath()` comparison. Closes a path-traversal vector where a malicious template name (e.g. `../../etc/passwd`) could escape the suggestions directory.
- Bounded resource usage in the DBAL-only autoload path: capped iteration counts, recursion depth, and intermediate buffer sizes in the SQL parsers and injection-pattern detectors so a hostile or pathological query can no longer exhaust memory or stall the profiler thread. Hardens the bundle against DoS via crafted SQL when the profiler is exposed in dev environments.

## [2.8.6] - 2026-04-25

### Fixed

- `ColumnTypeAnalyzer`: added configurable `excluded_fields` list (default: `mimeType`, `contentType`, `mediaType`, `fileType`) to prevent false enum opportunity alerts on MIME-type fields that match enum patterns but are not enums.
- `OrderByWithoutLimitAnalyzer`: added configurable `min_execution_time_ms` threshold (default: `10ms`); array-result queries below the threshold are now flagged as `info` instead of `warning`, with a description warning that production data growth will degrade performance.
- `OrderByWithoutLimitAnalyzer`: improved suggestion template for bounded array-result queries (WHERE clause present) — now recommends adding an index on the ORDER BY column, adding `setMaxResults`, or suppressing the alert via config when the collection is guaranteed small.
- `GetReferenceAnalyzer`: removed wildcard `*_id` column patterns that caused false positives on FK columns (e.g. `deposit_request_id`). Detection is now restricted to strict `id` primary key columns only, since FK columns return collections and are not candidates for `getReference()`.
- `DoctrineDoctorDataCollector`: bootstrap entry points (`index.php`, `autoload_runtime.php`, `autoload.php`) are now excluded when searching for the first application frame in `exclude_paths` filtering. Previously these files appeared at the bottom of every backtrace and short-circuited vendor exclusion for framework-internal queries (e.g. EasyAdmin entity loading).

## [2.8.0] - 2026-03-29

### Added

- New `InheritanceStrategyAnalyzer` family: detects invalid or risky inheritance mappings, including missing discriminator maps in STI, sparse STI tables, unsupported OneToMany associations on mapped superclasses, non-root `#[InheritanceType]` declarations, non-nullable subclass columns in STI, deep CTI hierarchies, and thin CTI subclasses.
- New `UniqueEntityWithoutDatabaseIndexAnalyzer`: detects `#[UniqueEntity]` constraints that are not backed by a database `UNIQUE` index, including Symfony validation metadata declared with attributes, YAML, and XML.
- New `DenormalizedAggregateWithoutLockingAnalyzer`: detects denormalized aggregate fields updated alongside collections without optimistic or pessimistic locking.
- Mutable datetime detection in `ColumnTypeAnalyzer`: flags mutable Doctrine date/time column types and suggests immutable equivalents to avoid silent state corruption.
- New `EagerLoadingMappingAnalyzer`: detects associations declared with `fetch: 'EAGER'` in entity mapping and suggests deferring fetch strategy decisions to queries.
- New `GedmoExtensionPerformanceAnalyzer`: detects entities using Gedmo `Loggable` or `Translatable` patterns that implicitly generate extra database queries.
- New `LazyGhostObjectsDisabledAnalyzer`: detects Doctrine ORM configurations where `enable_lazy_ghost_objects` is not enabled on supported Symfony versions.
- New `ManyToManyWithExtraColumnsAnalyzer`: detects ManyToMany join tables containing extra columns and recommends promoting them to an explicit join entity.
- New `MissingVersionFieldForConcurrencyAnalyzer`: detects entities involved in concurrent write patterns without an `#[ORM\Version]` field for optimistic locking.
- New `FlushInEventListenerAnalyzer`: detects `flush()` calls inside Doctrine lifecycle callbacks that can trigger re-entrant UnitOfWork computation or infinite loops.

### Changed

- Split the analyzer contract into dedicated query and metadata interfaces, enabling metadata-based analyzers without changing existing integration points.
- `UniqueEntityWithoutDatabaseIndexAnalyzer` now supports Symfony validation metadata declared in YAML and XML in addition to PHP attributes.
- Reduced NPath complexity in `HardcodedDatabaseCredentialsAnalyzer` and `UniqueEntityWithoutDatabaseIndexAnalyzer`.
- Improved the contribution and analyzer documentation around the detection pipeline and metadata analyzer workflow.

### Fixed

- `MissingIndexAnalyzer`: no longer reports a false positive when the relevant index is already used.
- Prevented XSS in profiler backtrace JSON output.
- Resolved CI regressions around ECS, PHPMD, and template validation, including complexity cleanup in `LazyGhostObjectsDisabledAnalyzer`.
- Added dedicated tests for `MetadataAnalyzerTrait` and the split analyzer interface contract.

### References

- Related PRs: #76, #77, #78, #79, #80, #81, #82, #97, #98, #99, #100.

## [2.7.3] - 2026-03-12

### Added

- New `OverprivilegedDatabaseUserAnalyzer`: detects privileged, empty, or passwordless database users and suggests switching to a least-privilege account.
- New `HardcodedDatabaseCredentialsAnalyzer`: detects database credentials embedded directly in DBAL configuration and suggests moving them to environment variables.
- Repeated lookup detection in `NPlusOneAnalyzer`: identifies repeated `findBy()`/`findOneBy()`-style lookups on non-key columns and suggests batching with `IN` queries or request-level caching.

### Changed

- `SensitiveDataExposureAnalyzer`: now also flags public getters that expose sensitive entity fields without explicit protection.
- `PropertyTypeMismatchAnalyzer`: now attaches concrete fix suggestions for PHP/Doctrine type mismatches, including nullability mismatches.
- `CollectionInitializationAnalyzer` suggestion template now uses the actual `mappedBy` value when available.

### References

- Related PRs: #69.

## [2.7.2] - 2026-03-12

### Fixed

- `CollectionInitializationAnalyzer`: now supports PHP constructor promotion when detecting collection initialization, fixing the false positive reported in issue `#67`.

### Changed

- Refactored collection initialization detection around dedicated initialization patterns, including promoted properties.
- Removed the duplicate regex-based `CollectionEmptyAccessAnalyzer` in favor of the AST-based collection initialization analysis path.

### References

- Related issues: #67.
- Related PRs: #68.

## [2.7.1] - 2026-03-11

### Fixed

- `SQLInjectionInRawQueriesAnalyzer`: now detects unparameterized literals in WHERE clauses of raw SQL queries as an injection risk, instead of only flagging active attack patterns.
- `DQLInjectionAnalyzer`: now detects Doctrine-generated SQL with concatenated literals and empty bound parameters, indicating unsafe DQL string concatenation.

## [2.7.0] - 2026-03-11

### Added

- New `OneToOneInverseSideAnalyzer`: detects bidirectional OneToOne `mappedBy` sides that silently force Doctrine to execute N+1 queries on every load, even when the relation is never accessed. Suggests flipping the owning side, going unidirectional, or using a fetch join.
- Configuration node for `one_to_one_inverse_side` analyzer.

### Changed

- `CompositeKeyComplexityAnalyzer`: use `ShortClassNameTrait`, proper return types, and `MappingHelper` for Doctrine 2/3/4 compatibility.

### References

- Related PRs: #66.

## [2.6.0] - 2026-03-10

### Added

- New `CompositeKeyComplexityAnalyzer`: detects entities using composite primary keys that limit Doctrine ORM features (no `getReference()`, slower identity map, complex FK mappings). Severity: WARNING for 2 columns, CRITICAL for 3+ or when referenced by other entities.
- Configuration node for `composite_key_complexity` analyzer.

## [2.5.1] - 2026-03-10

### Fixed

- Hardened all suggestion templates against incomplete context, null values, and division by zero. Templates no longer crash on missing keys or unexpected types.
- Added tests ensuring every template renders without exception with an empty context.

## [2.5.0] - 2026-03-10

### Changed

- `OnDeleteCascadeMismatchAnalyzer` now assigns CRITICAL severity for `orm_cascade_db_setnull` and `orm_orphan_db_setnull` mismatches (previously WARNING).
- Suggestion templates for `on_delete_cascade_mismatch` now render context-aware code snippets per mismatch type instead of a generic template.

## [2.4.0] - 2026-03-10

### Added

- New `JoinColumnNonPrimaryKeyAnalyzer`: detects associations where `referencedColumnName` points to a non-primary-key column, which causes incorrect lazy-loading proxy behavior.
- New `DuplicatePrivateFieldInHierarchyAnalyzer`: detects private fields with the same name in an entity and its mapped parent classes, which triggers MappingException or unpredictable Collection filtering.
- Configuration nodes for `join_column_non_primary_key`, `duplicate_private_field_in_hierarchy`, `overprivileged_database_user`, and `hardcoded_database_credentials` analyzers.

### References

- Related PRs: #63.

## [2.3.0] - 2026-03-08

### Fixed

- DivisionByZero premature dedup, PHPDoc return type, blameable template duplicates.
- N+1 descriptions, removed fictitious `ORM\BatchFetch`, fixed `isVendorCode` detection.
- False positives across 8 analyzers.
- SQL injection detection heuristics.
- PHPStan and PHPMD violations: unused constant, type assertions, short variable rename.

### Changed

- Use Webmozart Assert instead of native assert for type narrowing.
- Analyzer and suggestion wording polish.
- Analyzer config values made user-configurable with config coverage gaps fixed.

### References

- Related PRs: #61.

## [2.2.2] - 2026-03-04

### Changed

- Refined profiler tab navigation labels with dedicated icon/label/count markup for clearer readability.
- Updated suggestion action labels in the issue panel (`Suggested Fix`, `Hide suggestion`).
- Reduced tab control height and tightened spacing for a denser profiler header layout.

### Fixed

- Improved dark theme colors for the **Slowest Queries** table (header, rows, hover states, SQL block, and action button contrast).
- Harmonized light-mode `issue-body` background color to `#fffefc` for better visual consistency.
- Removed tab top-accent hover artifacts and ensured tabs fill the full available row width.

## [2.2.1] - 2026-03-04

### Fixed

- `doctrine_doctor.enabled` now supports Symfony parameter placeholders (e.g. `%kernel.debug%`) by resolving the root `enabled` config before strict tree validation.
- Added regression coverage for string boolean values and `%kernel.debug%` placeholder handling in DI extension tests.

## [2.2.0] - 2026-03-04

### Changed

- Removed "Show detailed rationale" toggle button from suggestion panels.
- Removed SVG icons from suggestion headers for consistent compact grid layout.
- Removed "Suggested Fix:" prefix from Performance suggestion titles.
- Renamed default suggestion title from "Code Quality Suggestion" to "Suggestion".
- Bumped PHPMD CouplingBetweenObjects threshold to 25.

## [2.1.3] - 2026-03-04

### Fixed

- PHPStan `isset.initializedProperty` error: use `ReflectionProperty::isInitialized()` for readonly property check after unserialization.
- Missing `rel="noopener noreferrer"` on external Doctrine documentation link (`target="_blank"`).
- Duplicate deduplication key normalization applied per-source before fallback selection.

### Changed

- Softer color palette for `.alert-warning`, `.alert-danger`, and `.dd-suggestion-meta-intro` blocks (less aggressive text contrast).
- Reduced font-size, padding, and margin across alert and suggestion-meta blocks for a more compact profiler panel.

## [2.1.2] - 2026-03-03

### Added

- Prism.js syntax highlighting in profiler suggestion code blocks (with line numbers support and copy actions).

### Changed

- Profiler panel refactored: large inline CSS/JS moved to dedicated Twig partials for maintainability.
- Issue descriptions in profiler normalized to a compact, impact-focused format for better readability.
- Profiler UI/UX theme refreshed (softer paper-like palette, spacing/typography cleanup, action controls restyled, issue/suggestion block organization improved).

### Fixed

- Circular interface inheritance between `IssueInterface` and `DeduplicatableIssueInterface`.
- Collector serialization edge case: stats can be computed from serialized profiler data without runtime failure.
- Profiler JavaScript resilience when Prism is unavailable (guarded highlighting path).
- Template code rendering safety for apostrophes/quotes in dynamic snippets.
- Deduplication contract checks hardened in tests and CI static-analysis regressions resolved.

### References

- Related PRs: #47, #48, #49, #50, #51, #52, #53, #55, #56, #57, #58, #59.

## [2.1.1] - 2026-02-25

### Fixed

- Early return in extension when bundle is disabled: no longer registers Twig paths or loads services unnecessarily (by @ClementDuverge in #43)
- Validate issue/suggestion class types before instantiation in `IssueReconstructor` (#32)

### Added

- Tests for extension enabled/disabled behavior (#44)

### References

- Related PRs: #32, #43, #44.

## [2.1.0] - 2026-02-24

### Fixed

- Wrap plain text suggestions in `<pre><code>` to prevent entity encoding
- Enable native lazy objects for Doctrine ORM test EntityManager

### Added

- Symfony 8 compatibility

### Changed

- Widen `webmozart/assert` constraint to support v2.x
- Remove unused `bitbag/coding-standard` dependency

### References

- Related PRs: #10.

## [2.0.0] - 2026-02-20

### Breaking Changes

- PHP ^8.4 minimum
- `doctrine/doctrine-bundle` ^3.0 (drop ^2.x)
- `doctrine/orm` ^3.0|^4.0 (drop ^2.x)
- `webmozart/assert` ^1.12

### Added

- CartesianProductAnalyzer: dedicated O(n^m) detection for queries with multiple unrelated JOINs

### Fixed

- Profiler suggestion rendering: inject `PhpTemplateRenderer` into `IssueReconstructor` so template rendering works after Symfony profiler deserialization
- `SafeContext::offsetGet()` now returns `null` for missing keys instead of throwing, enabling safe array destructuring in templates with optional context variables
- Nullable constructor parameters causing 93 PHPStan errors
- Missing `trigger_location` in `eager_loading` template
- Wrong key in `left_join_with_not_null` template (`table_name` -> `entity`)
- Segfault in FrankenPHP worker mode
- N+1 collection-aware suggestions with trigger location

### Changed

- Codebase migrated to PHP 8.4 via Rector (`#[\Override]`, typed constants, `array_find()`)
- PHPMD upgraded to 3.x-dev (PHP 8.4 support)
- Removed `ini_set('memory_limit')` runtime manipulation
- CI: PHP 8.4 only

### Removed

- PHP 8.2 / 8.3 support
- `doctrine/doctrine-bundle` ^2.x support
- `doctrine/orm` ^2.x support

### Performance

- GetReferenceAnalyzer: SQL parsing cache (159ms -> 31ms)
- Cache warmup on unique SQL patterns only

### References

- Related PRs: #7, #8.

## [1.0.0] - Initial Release

### Added

- 90+ specialized analyzers for Doctrine ORM
- Integration with Symfony Web Profiler
- Real-time performance analysis during development
- N+1 query detection with backtrace
- Missing index detection
- Security vulnerability scanning
- DQL/SQL injection detection
- Query optimization suggestions
- Zero-configuration setup
