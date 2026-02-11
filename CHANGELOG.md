# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0-beta.3]

### Breaking Changes

- PHP ^8.4 minimum
- `doctrine/doctrine-bundle` ^3.0 (drop ^2.x)
- `doctrine/orm` ^3.0|^4.0 (drop ^2.x)
- `webmozart/assert` ^1.12

### Removed

- PHP 8.2 / 8.3 support
- `doctrine/doctrine-bundle` ^2.x support
- `doctrine/orm` ^2.x support
- CI jobs for PHP 8.2 and 8.3

### Changed

- Codebase migrated to PHP 8.4 via Rector (`#[\Override]`, typed constants, `array_find()`)
- PHPMD upgraded to 3.x-dev (PHP 8.4 support)
- Removed `ini_set('memory_limit')` runtime manipulation
- CI: PHP 8.4 only

### Fixed

- Nullable constructor parameters causing 93 PHPStan errors
- Missing `trigger_location` in `eager_loading` template
- Wrong key in `left_join_with_not_null` template (`table_name` -> `entity`)
- Segfault in FrankenPHP worker mode
- N+1 collection-aware suggestions with trigger location

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
