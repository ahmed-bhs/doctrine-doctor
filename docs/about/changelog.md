---
layout: default
title: Changelog
parent: About
nav_order: 2
---

# Changelog

All notable changes to Doctrine Doctor will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- Documentation website with Just the Docs (Jekyll)
- Comprehensive examples for N+1 queries, missing indexes, and security issues
- Development guide for contributors

---

## [1.0.0] - 2024-XX-XX

### Added
- Initial release of Doctrine Doctor
- 66 specialized analyzers across 4 categories:
  - Performance (25 analyzers)
  - Security (8 analyzers)
  - Code Quality (20 analyzers)
  - Configuration (13 analyzers)
- Symfony Web Profiler integration
- Configurable thresholds and analyzer settings
- Template rendering system for suggestions
- Query backtrace support
- Issue deduplication
- Real-time analysis with LateDataCollector

### Performance Analyzers
- N+1 Query Detection
- Missing Index Detection
- Slow Query Detection
- Hydration Analysis
- Flush in Loop Detection
- And 20 more...

### Security Analyzers
- DQL Injection Detection
- SQL Injection Detection
- Sensitive Data Exposure
- Insecure Random Number Generation
- And 4 more...

### Code Quality Analyzers
- Cascade Configuration Issues
- Bidirectional Relationship Consistency
- Orphan Removal Issues
- Type Mismatches
- And 16 more...

### Configuration Analyzers
- Charset/Collation Validation
- Timezone Configuration
- MySQL Strict Mode
- InnoDB Engine Checks
- And 9 more...

---

## Version History

For complete version history and detailed changelogs, see:

- [GitHub Releases](https://github.com/ahmed-bhs/doctrine-doctor/releases)
- [Packagist](https://packagist.org/packages/ahmed-bhs/doctrine-doctor)

---

## Upgrade Guides

### Upgrading to 2.0.0 (Future)

When version 2.0.0 is released, upgrade instructions will be provided here.

---

## Contributing

Found a bug or have a feature request? Please check:

1. [Existing Issues](https://github.com/ahmed-bhs/doctrine-doctor/issues)
2. [Contributing Guide]({{ site.baseurl }}/contributing/overview)
3. [Development Setup]({{ site.baseurl }}/contributing/development)

---

**[← License](license)** | **[Home →]({{ site.baseurl }}/)**
