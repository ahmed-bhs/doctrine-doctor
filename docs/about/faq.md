---
layout: default
title: FAQ
parent: About
nav_order: 1
---

# FAQ - Frequently Asked Questions

Quick answers to common questions about Doctrine Doctor.

---

## Installation & Setup

### How do I install Doctrine Doctor?

```bash
composer require --dev ahmed-bhs/doctrine-doctor
```

That's it! Auto-configured via Symfony Flex.

### Does it work with Symfony 6 and 7?

Yes! Doctrine Doctor supports:
- **Symfony**: 6.0+ and 7.x
- **PHP**: 8.2+
- **Doctrine ORM**: 2.10+, 3.x, 4.x

### Do I need to configure anything?

No configuration required! It works out of the box. Optional configuration available in `config/packages/dev/doctrine_doctor.yaml`.

---

## Usage

### Where do I see the results?

1. Load any page in `dev` environment
2. Open Symfony Web Profiler (bottom toolbar)
3. Click "Doctrine Doctor" panel 🩺

### Can I see which line of code caused an issue?

Yes! Enable backtraces:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

### How do I disable specific analyzers?

```yaml
# config/packages/dev/doctrine_doctor.yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            enabled: false
```

---

## Performance

### Does it slow down my application?

**No!** Analysis runs AFTER the response is sent using `LateDataCollectorInterface`. Your users see no impact.

### Can I use it in production?

**No, never!** It's a dev-only tool:
- Installed with `--dev` flag
- Excluded from production builds
- Should be disabled in `prod` environment

### How much overhead does it add?

~10-50ms post-response (users don't notice). Heavy operations like EXPLAIN queries run after response.

---

## Analyzers

### How many analyzers are there?

**66 specialized analyzers** across 4 categories:
- Performance (25)
- Security (8)
- Code Quality (20)
- Configuration (13)

### Can I create custom analyzers?

Yes! Implement `AnalyzerInterface` and tag with `doctrine_doctor.analyzer`. [See guide]({{ site.baseurl }}/contributing/creating-analyzers).

### Which analyzers are most important?

Start with:
- **N+1 Query Analyzer** - Biggest performance impact
- **Missing Index Analyzer** - Critical for production
- **DQL/SQL Injection** - Security critical
- **Slow Query Analyzer** - Find bottlenecks

---

## Troubleshooting

### Panel not showing in profiler

Check:
1. You're in `dev` environment
2. Bundle registered in `config/bundles.php`
3. Run `php bin/console cache:clear`

### No issues detected

Possible reasons:
- No queries executed on this page
- Thresholds too high
- All code is optimized! ✨

### EXPLAIN queries failing

Ensure database user has `SELECT` permissions. Disable if needed:

```yaml
doctrine_doctor:
    analyzers:
        missing_index:
            explain_queries: false
```

---

## Configuration

### How do I adjust sensitivity?

Lower thresholds for stricter detection:

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            threshold: 2  # More strict (default: 5)
        slow_query:
            threshold: 50  # More strict (default: 100ms)
```

### Can I exclude vendor entities?

Yes, enabled by default:

```yaml
doctrine_doctor:
    analysis:
        exclude_third_party_entities: true
```

---

## Comparison

### Doctrine Doctor vs Static Analysis (PHPStan)?

Different tools, complementary:

| Feature | Doctrine Doctor | PHPStan |
|---------|----------------|---------|
| **Detects** | Runtime issues | Code issues |
| **N+1 queries** | ✅ Yes | ❌ No |
| **Missing indexes** | ✅ Yes (real DB) | ❌ No |
| **Type errors** | ❌ No | ✅ Yes |
| **When runs** | During requests | CI/pre-commit |

**Use both!** PHPStan for code quality, Doctrine Doctor for runtime performance.

### vs Symfony Profiler's Doctrine Panel?

Doctrine Doctor extends it:

| Feature | Doctrine Panel | Doctrine Doctor |
|---------|---------------|-----------------|
| **Query list** | ✅ Yes | ✅ Yes |
| **Execution time** | ✅ Yes | ✅ Yes |
| **N+1 detection** | ❌ No | ✅ Yes |
| **Suggestions** | ❌ No | ✅ Yes + code |
| **Security checks** | ❌ No | ✅ Yes |
| **66 analyzers** | ❌ No | ✅ Yes |

---

## Contributing

### How can I contribute?

See [Contributing Guide]({{ site.baseurl }}/contributing/overview). Ways to help:
- Report bugs
- Request features
- Submit PRs
- Write documentation
- Create analyzers

### Where do I report bugs?

[GitHub Issues](https://github.com/ahmed-bhs/doctrine-doctor/issues)

---

## License

### Can I use it commercially?

Yes! MIT License - free for commercial use.

### Can I modify it?

Yes! MIT License allows modifications and redistribution.

---

**Still have questions?** [Open a discussion](https://github.com/ahmed-bhs/doctrine-doctor/discussions)
