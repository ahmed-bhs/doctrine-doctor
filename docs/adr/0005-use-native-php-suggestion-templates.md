# ADR 0005: Use native PHP suggestion templates

- Status: Accepted
- Date: 2026-09-26

## Context

Suggestions contain generated explanations and code examples. Doctrine Doctor
is a Symfony bundle, but Twig is optional for some installations. The bundle
also needs safe context handling and a renderer boundary that applications can
replace without changing analyzers.

## Decision

Keep native PHP files as the default suggestion template format and expose
`TemplateRendererInterface` as the extension seam. The default
`PhpTemplateRenderer` executes templates with `SafeContext`; a Twig renderer is
available when Twig is installed, and applications may provide another renderer
through the interface alias.

Analyzers request a named template through the suggestion factory. They do not
render templates or depend on Twig directly.

## Consequences

The core bundle remains usable without a Twig dependency and suggestions avoid
an additional compilation layer in the common path. Template authors use
ordinary PHP and must respect the safe context contract. The renderer interface
and template names become extension surfaces that require compatibility care.

## Rejected alternatives

- Require Twig for every installation: rejected because it expands the core
  dependency surface for a developer tool.
- Render suggestions inside analyzers: rejected because it couples detection to
  presentation and makes alternate output formats harder.
- Allow arbitrary template context: rejected because it weakens the escaping
  and safety boundary documented in the template security guide.
