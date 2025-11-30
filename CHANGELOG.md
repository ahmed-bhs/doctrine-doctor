# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Performance
- **80% faster GetReferenceAnalyzer** with SQL parsing cache (159ms → 31ms)
- Added cache for `hasJoins()` and `hasComplexWhereConditions()` in CachedSqlStructureExtractor
- Optimized cache warmup to process only unique SQL patterns (283x reduction for duplicate queries)

### SEO
- Added comprehensive keywords for better discoverability on Packagist and GitHub
- Improved package description for search engines

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
