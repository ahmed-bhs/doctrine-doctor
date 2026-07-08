# AI Mate / MCP integration

Doctrine Doctor can expose its profiler findings to AI assistants (Claude Code,
Cursor, GitHub Copilot, …) over [MCP](https://modelcontextprotocol.io) through
[Symfony AI Mate](https://symfony.com/doc/current/ai/mate.html). It registers an MCP
tool, `doctrine-doctor-issues`, that reads a profiler request and returns the detected
issues — already sanitized for safe AI consumption.

This is **opt-in**. The bundle ships the integration code but pulls no AI dependency
by default. **Without AI Mate installed, none of this applies and Doctrine Doctor
runs exactly as before.**

## Setup in a Symfony project

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

`mate init` also writes a `.mcp.json` symlink at the project root. Claude Code reads it
automatically when started from that directory — no `claude mcp add` needed. On first use
it asks you to approve the MCP server once (it will not run an arbitrary server without
your consent). Other clients (Cursor, Copilot) are pointed at the same `mcp.json`.

Verify the tool is exposed with:

```bash
vendor/bin/mate mcp:tools:list --filter="doctrine*"
```

## The `doctrine-doctor-issues` tool

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
