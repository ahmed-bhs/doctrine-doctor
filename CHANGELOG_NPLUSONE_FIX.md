# Fix: NPlusOneAnalyzer False Positives on INSERT/UPDATE/DELETE Queries

## Problem
The NPlusOneAnalyzer was incorrectly detecting INSERT/UPDATE/DELETE queries as N+1 issues. This was particularly visible on the `/demo/flush-in-loop` route where 10 INSERT queries (from flush in loop) were being flagged as "N+1 queries (unknown)".

## Root Cause
- N+1 detection is specifically about **lazy loading** causing multiple **SELECT** queries
- INSERT/UPDATE/DELETE queries in loops are different anti-patterns (flush-in-loop, bulk operations)
- The analyzer was grouping all similar queries regardless of type

## Solution
Added filtering in `NPlusOneAnalyzer::analyze()` to only analyze SELECT queries:

```php
// Filter to only SELECT queries - N+1 is specifically about lazy loading
// INSERT/UPDATE/DELETE queries in loops are handled by other analyzers
$selectQueries = $queryDataCollection->filter(
    fn (QueryData $queryData): bool => $this->sqlExtractor->isSelectQuery($queryData->sql)
);
```

## Changes
- **Modified**: `src/Analyzer/NPlusOneAnalyzer.php`
  - Added SELECT query filter before grouping queries
  - INSERT/UPDATE/DELETE queries are now excluded from N+1 detection
  
- **Added Tests**: `tests/Analyzer/NPlusOneAnalyzerTest.php`
  - `it_excludes_insert_queries_from_n_plus_one_detection()` - Verifies 10 INSERT queries don't trigger N+1
  - `it_excludes_update_queries_from_n_plus_one_detection()` - Verifies 10 UPDATE queries don't trigger N+1
  - `it_excludes_delete_queries_from_n_plus_one_detection()` - Verifies 10 DELETE queries don't trigger N+1

## Test Results
- ✅ All 39 NPlusOneAnalyzer tests pass (36 existing + 3 new)
- ✅ New tests verify INSERT/UPDATE/DELETE exclusion
- ✅ Existing tests confirm no regression

## Impact
- **Before**: `/demo/flush-in-loop` showed false positive: "N+1 Query Detected: 10 queries (unknown)"
- **After**: Only FlushInLoopAnalyzer detects the issue (correct analyzer for this pattern)

## Note on LazyLoadingAnalyzer
The LazyLoadingAnalyzer was checked and does **not** have this issue because:
- It uses `detectLazyLoadingPattern()` which explicitly checks `instanceof SelectStatement`
- Non-SELECT queries are automatically filtered out at the SQL parser level
