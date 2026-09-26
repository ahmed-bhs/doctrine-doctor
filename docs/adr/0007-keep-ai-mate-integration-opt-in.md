# ADR 0007: Keep AI Mate integration opt-in

- Status: Accepted
- Date: 2026-09-26

## Context

Doctrine Doctor can expose profiler findings through Symfony AI Mate and MCP.
Profiler data may contain SQL, file paths, traces, and parameter values. Most
users do not need an AI integration, and adding it by default would expand the
dependency and data-sharing surface of a debugging bundle.

## Decision

Ship the integration code without requiring the AI Mate package. Users must
install the bridge and explicitly enable both extensions. The MCP capability
reads stored profiler findings only after opt-in and sanitizes parameters,
traces, vendor paths, and project paths before returning data.

The default Doctrine Doctor installation and profiler behavior remain unchanged
when AI Mate is absent or disabled.

## Consequences

The default dependency surface and data flow stay small. Users who enable the
feature must review their MCP client permissions and the information exposed by
their profiler. Sanitization and opt-in behavior are compatibility and security
contracts that require regression coverage.

## Rejected alternatives

- Enable the MCP server automatically: rejected because it would expose profiler
  data without an explicit user decision.
- Make AI Mate a required dependency: rejected because most installations only
  need the profiler and CI analyzers.
- Return raw profiler data to MCP clients: rejected because traces, paths, SQL,
  and parameters can contain sensitive information.
