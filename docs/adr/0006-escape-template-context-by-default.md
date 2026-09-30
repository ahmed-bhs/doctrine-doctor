# ADR 0006: Escape template context by default

- Status: Accepted
- Date: 2026-09-26

## Context

Suggestion templates render query text, paths, metadata, and generated code.
Some values can contain user-controlled or database-controlled text. Native PHP
templates do not provide Twig's automatic escaping by themselves, while legacy
templates may still use extracted variables.

## Decision

Pass template data through `SafeContext`. Property and array access escape by
default, while `raw()` is an explicit escape hatch for content that has already
been sanitized or is intentionally trusted. New templates must use the context
object; legacy extracted variables remain supported only for compatibility and
must escape values manually.

The renderer and template security guide document the allowed raw-content
boundary. An analyzer must never mark arbitrary user input as raw content.

## Consequences

New suggestions are safe by default and the trust boundary is visible in code
review. Existing templates can migrate incrementally, but compatibility support
means a legacy template can still bypass automatic escaping if it ignores the
context object. Security-sensitive template changes require focused escaping
tests.

## Rejected alternatives

- Trust every template author to escape every value: rejected because omissions
  are easy and the bundle renders diagnostic data from outside the template.
- Remove legacy extracted variables immediately: rejected because it would break
  existing custom templates without a migration path.
