# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `DoctrineCacheAnalyzer`: the YAML-based scan now detects missing `metadata_cache_driver`, `query_cache_driver`, and `result_cache_driver` keys inside an existing `when@prod` section, not only the explicit `type: array` case. Previously, a `when@prod` block that omitted these keys entirely was silently ignored, causing the critical performance issue (entity metadata reparsed on every request, -50 to -80%) to go unreported in dev. The absence of `when@prod` altogether is still not flagged to avoid false positives on projects using split `config/packages/prod/` files.
- Added `missing_cache_production.php` suggestion template used for the new "not configured" issues, distinct from `array_cache_production.php` which covers the explicit array cache case.
- `OrderByWithoutLimitAnalyzer`: fast queries (below `min_execution_time_ms`) with a FK equality predicate in the WHERE clause (e.g. `WHERE deposit_request_id = ?`) are now silently skipped. These are aggregate-child collections whose size is bounded by the parent entity lifecycle, not by data volume. If the query ever degrades (execution time exceeds the threshold), the alert re-enables automatically.
- `FinalEntityAnalyzer`: added early-return guard when `enable_native_lazy_objects` (PHP 8.4 ghost objects) is active. Ghost objects decorate rather than subclass the entity, so `final` classes are safe — the previous behavior produced false-positive CRITICAL issues on PHP 8.4 projects.

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
