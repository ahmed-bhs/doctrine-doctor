---
name: parsing-strategy
description: Choose safely between string checks, regular expressions, tokenizers, and parsers for SQL, PHP, DQL, and configuration analysis.
---

# Parsing Strategy Skill

1. Define the evidence and dialect first: exact marker, bounded token, nested
   syntax, or semantic structure.
2. Use direct string operations for exact case-insensitive markers when quoting,
   comments, nesting, and dialect variations cannot change the result.
3. Use a carefully bounded regular expression only for a small, local pattern
   with explicit limits. Anchor it, expose named captures, and test whitespace,
   quoting, comments, nesting, and malformed input where relevant.
4. Use a tokenizer when separators and quoted literals must be distinguished
   but a full syntax tree is unnecessary.
5. Use a maintained parser when the rule depends on nesting, joins, aliases,
   expressions, comments, dialect grammar, or semantic relationships. Prefer
   the project's existing parser dependency over a new ad-hoc grammar.
6. For PHP source, use `nikic/php-parser` and its AST rather than regex when
   syntax or names can be expressed structurally. For MySQL SQL, use
   `phpmyadmin/sql-parser` where its dialect coverage matches the input.
7. Keep parser failures explicit and non-fatal to unrelated analyzers. Return a
   diagnostic or skip with a reason instead of guessing and creating a false
   positive.
8. Hide the choice behind a small adapter or parser interface when it is used by
   more than one analyzer. Test representative valid, invalid, quoted, nested,
   and dialect-specific inputs.

References: [nikic/php-parser](https://github.com/nikic/PHP-Parser) and
[phpMyAdmin SQL Parser](https://github.com/phpmyadmin/sql-parser).
