# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

## [2.1.1] - 2026-02-25

### Fixed

- Early return in extension when bundle is disabled: no longer registers Twig paths or loads services unnecessarily (by @ClementDuverge in #43)
- Validate issue/suggestion class types before instantiation in `IssueReconstructor` (#32)

### Added

- Tests for extension enabled/disabled behavior (#44)

## [2.1.0] - 2026-02-24

### Fixed

- Wrap plain text suggestions in `<pre><code>` to prevent entity encoding
- Enable native lazy objects for Doctrine ORM test EntityManager

### Added

- Symfony 8 compatibility

### Changed

- Widen `webmozart/assert` constraint to support v2.x
- Remove unused `bitbag/coding-standard` dependency

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

## [1.0.0] - Initial Release

### Added

- 66 specialized analyzers for Doctrine ORM
- Integration with Symfony Web Profiler
- Real-time performance analysis during development
- N+1 query detection with backtrace
- Missing index detection
- Security vulnerability scanning
- DQL/SQL injection detection
- Query optimization suggestions
- Zero-configuration setup
