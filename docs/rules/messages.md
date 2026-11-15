# Issue and suggestion messages

Findings are read in the Web Profiler, in a terminal and on screen during code review.
Each one must be readable in a few seconds: the fact, the consequence, the fix.

## Issue title

- Names the problem and the object: `N+1 Query Detected: 89 queries on sylius_product_variant`,
  `Float Used for Money: StockAlert::$targetPrice`.
- No emoji, no exclamation mark, no severity word (the badge already shows it).

## Issue description

- **Two sentences at most, about 250 characters.**
- Sentence 1: the measured fact, with the numbers the analyzer has (query count, time, rows, table, field).
- Sentence 2: the consequence for the application, stated plainly.
- No list, no tutorial, no fix (the suggestion carries the fix).

```text
Before: Over-eager loading detected: 7 collection JOINs (OneToMany/ManyToMany) in a single query. This can
cause significant data duplication and memory waste. ⚠️ Each collection JOIN multiplies the result rows. With 7
collection JOINs, you may be loading the same parent entity data hundreds or thousands of times. Example: 1 parent
× 10 items × 5 comments = 50 SQL rows for 1 entity! Consider using separate queries, batch fetching, or loading
only the relations you actually need.

After:  The query fetch-joins 7 collections, so every row of one collection is repeated for each row of the others.
1 parent with 10 items and 5 comments already returns 50 rows.
```

## Suggestion template

- **Header**: an imperative phrase, 50 characters at most (`Load each collection in its own query`).
- **Why**: one short paragraph, two sentences at most, using the facts passed in the context.
- **One code block**, 15 lines at most. Prefer `// Before` / `// After` in the same block, or only the fixed code.
  Code must be correct for the ORM and DBAL versions the bundle supports.
- **At most one alternative**, in one sentence, with an optional code block of 8 lines at most.
- **One documentation link** through `suggestionDocLink()` when an official page exists.
- Rendered text (without code) stays under about 600 characters.

## Banned everywhere

- Emoji and pictographs in descriptions, suggestions and titles (`⚠️ ✅ 🔴 😱 …`). The CLI report adds two markers of
  its own around the message, as PHPStan does: 🪪 before the finding type and 💡 before the fix.
- Exclamation marks, `BAD:` / `GOOD:` in capitals, `WRONG` / `CORRECT` banners.
- Inflated wording: *severe(ly)*, *massive*, *exponential(ly)*, *huge*, *dramatic(ally)*, *catastrophic*,
  *Mathematical Reality*, invented percentages (*50-70% faster*) that the analyzer did not measure.
- Several stacked alerts or bullet lists that repeat the description.

## Checklist when editing a message

1. Does the first sentence state a fact the analyzer measured?
2. Could a developer apply the fix from the code block alone?
3. Is every number either measured or clearly an example?
