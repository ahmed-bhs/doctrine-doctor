# Doctrine Doctor

<img src="docs/images/logo.png" alt="Doctrine Doctor Logo" width="80" align="right">

**Runtime Analysis Tool for Doctrine ORM — Integrated into Symfony Web Profiler**

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4+-777BB4.svg?logo=php&logoColor=white)](https://php.net)
[![Symfony 6.x | 7.x | 8.x](https://img.shields.io/badge/Symfony-6.x%20%7C%207.x%20%7C%208.x-000000.svg?logo=symfony&logoColor=white)](https://symfony.com)
[![Doctrine ORM](https://img.shields.io/badge/Doctrine-3.x%20%7C%204.x-FC6A31.svg?logo=doctrine&logoColor=white)](https://www.doctrine-project.org)
[![License MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![CI](https://github.com/ahmed-bhs/doctrine-doctor/workflows/CI/badge.svg)](https://github.com/ahmed-bhs/doctrine-doctor/actions)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](https://www.php-fig.org/psr/psr-12/)
[![Packagist Version](https://img.shields.io/packagist/v/ahmed-bhs/doctrine-doctor.svg)](https://packagist.org/packages/ahmed-bhs/doctrine-doctor)

<b>Why Runtime Analysis?</b>

<p>Unlike static analysis tools (PHPStan, Psalm) that analyze code without execution, Doctrine Doctor:</p>

<ul>
<li><b>Detects runtime doctrine issues</b> by analyzing real execution context (actual parameters, data volume, query plans), including N+1 queries, real query performance bottlenecks, and missing indexes.</li>
<li><b>Integrated into your workflow</b>: Results appear directly in Symfony Web Profiler during development
  <ul>
  <li>&#x1F4CD; Backtrace: Points to exact template line</li>
  <li>&#x1F4A1; Suggestion: Use <code>->addSelect(..)</code> to eager load products</li>
  </ul>
</li>
</ul>

<p align="center">
  <img src="https://github.com/ahmed-bhs/doctrine-doctor-assets/raw/main/demo-styled.png" alt="Doctrine Doctor Demo" width="100%">
</p>

---

## Features

### 90+ Specialized Analyzers

- **Performance** — Detects N+1 queries, missing database indexes, slow queries, excessive hydration,
  findAll() without limits, setMaxResults() with collection joins, too many JOINs, and query caching
  opportunities
- **Security** — Identifies DQL/SQL injection vulnerabilities, QueryBuilder SQL injection risks,
  sensitive data exposure in serialization, unprotected sensitive fields, and insecure random generators
- **Integrity** — Detects cascade configuration issues, bidirectional inconsistencies,
  missing orphan removal, type mismatches, float usage for money, uninitialized collections,
  EntityManager in entities, and architectural violations
- **Configuration** — Validates database charset/collation settings, timezone handling,
  Gedmo trait configurations, MySQL strict mode, and other database-level configurations

---

## ⚡ Quick Start (30 seconds)

**Step 1: Install**

```bash
composer require --dev ahmed-bhs/doctrine-doctor
```

**Step 2: That's it!**

Auto-configured via [Symfony Flex](https://github.com/symfony/recipes-contrib/pull/1882). No YAML, no configuration files needed.

**Step 3: See it in action**

1. Refresh any page in your Symfony app (in `dev` environment)
2. Open the **Symfony Web Profiler** (bottom toolbar)
3. Click the **"Doctrine Doctor"** panel 🩺

## Configuration (Optional)

Configure thresholds in `config/packages/dev/doctrine_doctor.yaml`:

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            threshold: 5  # default, lower to 3 to be stricter
        slow_query:
            threshold: 100  # milliseconds (default)
```

**Enable backtraces** to see WHERE in your code issues originate:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

[Full configuration reference →](docs/user-guide/configuration.md)

---

## AI Mate / MCP integration (optional)

Doctrine Doctor can expose its profiler findings to AI assistants (Claude Code,
Cursor, GitHub Copilot, …) over [MCP](https://modelcontextprotocol.io) through
[Symfony AI Mate](https://symfony.com/doc/current/ai/mate.html). It registers an MCP
tool, `doctrine-doctor-issues`, that reads a profiler request and returns the detected
issues — already sanitized for safe AI consumption.

This is **opt-in**. The bundle ships the integration code but pulls no AI dependency
by default. **Without AI Mate installed, nothing below applies and Doctrine Doctor
runs exactly as before.**

### Setup in a Symfony project

**1. Install the Symfony bridge** (dev only — this is a development/debugging tool,
never for production):

```bash
composer require --dev symfony/ai-symfony-mate-extension
```

**2. Initialize AI Mate** — creates the `mate/` config directory and an `mcp.json`
your AI client reads:

```bash
vendor/bin/mate init
composer dump-autoload
```

**3. Discover extensions** — scans installed packages for `extra.ai-mate` config and
registers Doctrine Doctor in `mate/extensions.php`:

```bash
vendor/bin/mate discover
```

**4. Enable Doctrine Doctor.** For safety, no vendor extension is active until you opt
in. Edit `mate/extensions.php`:

```php
return [
    'ahmed-bhs/doctrine-doctor' => ['enabled' => true],
    'symfony/ai-symfony-mate-extension' => ['enabled' => true],
];
```

**5. (Optional) Point AI Mate at your profiler directory** if it is not the default,
in `mate/config.php`:

```php
$container->parameters()
    ->set('ai_mate_symfony.profiler_dir', '%mate.root_dir%/var/cache/dev/profiler');
```

**6. Generate some traffic** (the profiler must have stored at least one request), then
start the MCP server:

```bash
vendor/bin/mate serve
```

Claude Code auto-detects the generated `mcp.json` at the project root — no manual server
registration needed. Verify the tool is exposed with:

```bash
vendor/bin/mate mcp:tools:list --filter="doctrine*"
```

### The `doctrine-doctor-issues` tool

| Argument | Default | Description |
|---|---|---|
| `token` | latest request | Profiler token to read; omitted uses the most recent request |
| `category` | all | `performance`, `security`, `integrity`, `configuration` |
| `severity` | all | `critical`, `warning`, `info` |
| `limit` | `20` | Max issues returned |
| `includeQueries` | `false` | Include the SQL behind each issue |

Traces, SQL snippets, and bound parameters are sanitized before leaving the profiler:
sensitive parameters are redacted, vendor/cache frames are stripped, and paths are
relativized to the project root.

---

## Example: N+1 Query Detection

<table>
<tr>
<td width="50%" align="center"><b>Before — 100 queries</b></td>
<td width="50%" align="center"><b>After — 1 query</b></td>
</tr>
<tr>
<td>

```php
$users = $repository->findAll();
```

```twig
{% for user in users %}
    {{ user.profile.bio }}
{% endfor %}
```

</td>
<td>

```php
$users = $repository
    ->createQueryBuilder('u')
    ->leftJoin('u.profile', 'p')
    ->addSelect('p')
    ->getQuery()
    ->getResult();
```

</td>
</tr>
<tr>
<td colspan="2">

**Doctrine Doctor detects the N+1 pattern at runtime** — reports query count,
execution time, points to the exact template line, and suggests eager loading with `addSelect()`.

</td>
</tr>
</table>

---

## Documentation

| Document | Description |
|----------|-------------|
| [**Full Analyzers List**](docs/user-guide/analyzers.md) | Complete catalog of all **90+ analyzers** covering performance, security, integrity, and configuration - find the perfect analyzer for your specific needs |
| [**Architecture Guide**](docs/advanced/architecture.md) | Deep dive into **system design**, architecture patterns, and technical internals - understand how Doctrine Doctor works under the hood |
| [**Configuration Reference**](docs/user-guide/configuration.md) | Comprehensive guide to **all configuration options** - customize analyzers, thresholds, and outputs to match your workflow |
| [**Template Security**](docs/advanced/template-security.md) | Essential **security best practices** for PHP templates - prevent XSS attacks and ensure safe template rendering |

---

## Contributing

See [Contributing Guide](docs/contributing/overview.md) for guidelines.

## License

MIT License - see [LICENSE](LICENSE) for details.

<div align="right">

---

**Created by [Ahmed EBEN HASSINE](https://github.com/ahmed-bhs)**

<a href="https://github.com/sponsors/ahmed-bhs" target="_blank">
  <img src="https://img.shields.io/static/v1?label=Sponsor&message=GitHub&logo=github&style=for-the-badge&color=blue"
       alt="Sponsor me on GitHub" style="height: 32px !important; border-radius: 5px !important;">
</a>

<a href="https://www.buymeacoffee.com/w6ZhBSGX2" target="_blank">
  <img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png"
       alt="Buy Me A Coffee" style="height: 32px !important; width: 128px !important; border-radius: 5px !important;">
</a>

</div>
