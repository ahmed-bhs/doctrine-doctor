# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.5] - 2026-03-04

### Fixed

- `doctrine_doctor.enabled` now supports Symfony parameter placeholders (e.g. `%kernel.debug%`) by resolving the root `enabled` config before strict tree validation.
- Added regression coverage for string boolean values and `%kernel.debug%` placeholder handling in DI extension tests.

## [1.0.4] - 2026-02-26

### Fixed

- Early return in DoctrineDoctorExtension when bundle is disabled (no services loaded, no twig paths registered)
- Validate class types before instantiation in IssueReconstructor (closes #31)
- Wrap plain text suggestions in `<pre><code>` to prevent HTML entity encoding in profiler
- Isolate PropertyTypeMismatchAnalyzer test fixtures to avoid false positives from unrelated entities
- Fix SafeContext test to match offsetGet null-return design for missing keys

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
