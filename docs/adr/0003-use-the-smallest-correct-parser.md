# ADR 0003: Use the smallest correct parser

- Status: Accepted
- Date: 2026-09-26

## Context

Some analyzers inspect SQL or PHP source. A direct string check or bounded regex
is fast and readable for a local marker, but it cannot reliably distinguish
quoted text, comments, nesting, aliases, or language structure. Replacing every
regex with a full parser would add cost and complexity where no structure is
needed.

## Decision

Choose the least powerful technique that is correct for the evidence:

1. direct string operations for exact markers;
2. bounded regular expressions for small, documented patterns;
3. tokenization when delimiters and quoted literals matter;
4. a maintained parser and AST when nesting, grammar, aliases, comments, or
   semantic relationships matter.

Use the existing `nikic/php-parser` dependency for PHP source and
`phpmyadmin/sql-parser` for supported MySQL SQL. Keep parser access behind small
adapters such as `SqlStructureExtractor`. Parser failures must be explicit and
must not make unrelated analyzers guess; a documented fallback may be used only
when it is conservative and covered by tests.

## Consequences

Simple checks remain cheap in the profiler. Structural analyzers share mature
parsing behavior and avoid fragile regex growth. Parser dialect and error
handling become part of each analyzer's contract, so tests must cover quoted,
nested, malformed, and dialect-specific input where relevant.

## Rejected alternatives

- Use regex for every SQL or PHP rule: rejected because syntax and nesting create
  false positives and false negatives.
- Parse every string with a full AST: rejected because it adds latency and
  complexity for exact-marker checks.
- Add a new parser for each analyzer: rejected because it duplicates grammar
  handling and increases dependency and maintenance cost.
