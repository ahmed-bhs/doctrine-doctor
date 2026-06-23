# External Analyzer Ideas For Doctrine Doctor

This document summarizes analyzer ideas discovered while exploring external projects that could be useful for `doctrine-doctor`.

Explored repositories:

- `staabm/phpstan-dba`
- `slowql/slowql`
- `erikdarlingdata/PerformanceStudio`
- `houqp/sqlvet`
- `montraydavis/MDLabs.TypeChat-CriticalSqlAnalysis`

## Main Conclusion

The two strongest sources are:

1. `phpstan-dba`
2. `slowql`

`PerformanceStudio` is useful mainly for execution-plan heuristics, not for direct code reuse in the current PHP/Doctrine architecture.

`sqlvet` is a lighter source of ideas for syntax/schema validation.

`MDLabs.TypeChat-CriticalSqlAnalysis` is mostly prompt-driven scoring and reporting, not a solid analyzer engine to port.

## Best Candidates To Add

### 1. PlaceholderMismatchAnalyzer

External source classes/files:

- `phpstan-dba/src/QueryReflection/PlaceholderValidation.php`
- optionally related `phpstan-dba/src/Rules/PdoStatementExecuteMethodRule.php`

Detect:

- named placeholder missing from bound values
- extra named parameter not present in SQL
- wrong number of positional placeholders
- mismatch between optional and required parameters

Current Doctrine Doctor coverage:

- partially covered only at higher level by `src/Analyzer/Integrity/QueryBuilderBestPracticesAnalyzer.php`
- not covered as a dedicated DBAL/raw-SQL placeholder validator

Recommendation:

- implement as a new analyzer
- very high ROI

### 2. SelectStarAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/scanning.py` class `SelectStarRule`
- `sqlvet/pkg/vet/vet.go` helper `columnRefToColumnUsed()` explicitly ignores `SELECT *`

Detect:

- `SELECT *` in runtime SQL
- stricter warning for slow queries using `SELECT *`
- optionally stronger severity if combined with joins or large result sets

Current Doctrine Doctor coverage:

- partially covered only through suggestion text in templates and optimization hints
- not covered as a dedicated analyzer

Recommendation:

- implement as a new analyzer
- high ROI

### 3. NotInSubqueryAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/scanning.py` class `NotInSubqueryRule`
- `slowql/src/slowql/rules/performance/scanning.py` class `NotInNullableSubqueryRule`

Detect:

- `NOT IN (SELECT ...)`
- especially risky when the subquery can contain `NULL`

Current Doctrine Doctor coverage:

- not currently covered

Recommendation:

- implement as a new analyzer
- high ROI

### 4. DeepOffsetPaginationAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/indexing.py` class `DeepOffsetPaginationRule`

Detect:

- large `OFFSET` values
- pagination patterns that should move to keyset/cursor pagination

Current Doctrine Doctor coverage:

- partially covered by `src/Analyzer/Performance/OrderByWithoutLimitAnalyzer.php`
- not covered for deep-offset pagination specifically

Recommendation:

- implement as a new analyzer
- high ROI

### 5. ImplicitTypeConversionAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/indexing.py` class `ImplicitTypeConversionRule`
- `PerformanceStudio/src/PlanViewer.Core/Services/PlanAnalyzer.cs` warning types including `Implicit Conversion` and `Data Type Mismatch`

Detect:

- numeric column compared to string literal
- string column compared to numeric literal
- predicates likely to trigger implicit conversion and disable index usage

Current Doctrine Doctor coverage:

- not covered as a dedicated analyzer
- only indirectly surfaced today through slow query or explain-based symptoms

Recommendation:

- implement as a new analyzer
- high ROI

### 6. FunctionOnPredicateColumnAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/indexing.py` class `FunctionOnIndexedColumnRule`
- `PerformanceStudio/src/PlanViewer.Core/Services/PlanAnalyzer.cs` regex `FunctionInPredicateRegex`

Detect:

- `LOWER(col) = ...`
- `COALESCE(col, ...)`
- `DATE(col)`, `CAST(col ...)`, `ISNULL(col, ...)`
- any function wrapping filtered columns and defeating indexes

Current Doctrine Doctor coverage:

- partially covered by `YearFunctionOptimizationAnalyzer`
- not covered as a general function-on-predicate analyzer

Recommendation:

- implement as a new analyzer
- high ROI

### 7. DbalRawQueryValidationAnalyzer

External source classes/files:

- `phpstan-dba/docs/rules.md`
- `phpstan-dba/src/Rules/SyntaxErrorInQueryMethodRule.php`
- `phpstan-dba/src/Rules/SyntaxErrorInPreparedStatementMethodRule.php`
- `phpstan-dba/src/Rules/SyntaxErrorInQueryFunctionRule.php`
- `sqlvet/pkg/vet/vet.go`

Detect:

- suspicious `executeQuery`, `executeStatement`, `prepare`, `createNativeQuery` usage
- SQL shape problems in raw DBAL calls
- missing parameter binding opportunities

Current Doctrine Doctor coverage:

- partially covered by `src/Analyzer/Security/SQLInjectionInRawQueriesAnalyzer.php`
- partially covered by `src/Analyzer/Integrity/QueryBuilderBestPracticesAnalyzer.php`
- not covered as a unified DBAL/raw-query validation analyzer

Recommendation:

- implement as a new analyzer
- medium to high ROI

### 8. UnboundedSelectAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/scanning.py` class `UnboundedSelectRule`

Detect:

- `SELECT` without `LIMIT`
- non-aggregated fetches likely to pull too many rows

Current Doctrine Doctor coverage:

- partially covered by `src/Analyzer/Performance/FindAllAnalyzer.php`
- partially covered by `src/Analyzer/Performance/OrderByWithoutLimitAnalyzer.php`
- not covered as a general native-SQL unbounded select analyzer

Recommendation:

- possible new analyzer, but scope carefully to avoid noise
- medium ROI

### 9. ReadModifyWriteWithoutLockAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/reliability/race_conditions.py` class `ReadModifyWriteLockingRule`

Detect:

- `SELECT` followed by `UPDATE` patterns without `FOR UPDATE`
- read-modify-write sequences likely to suffer from race conditions

Current Doctrine Doctor coverage:

- partially covered at architectural level by `src/Analyzer/Integrity/TransactionBoundaryAnalyzer.php`
- partially covered at domain level by `src/Analyzer/Integrity/DenormalizedAggregateWithoutLockingAnalyzer.php`
- not covered as a raw SQL concurrency-pattern analyzer

Recommendation:

- implement only if raw SQL concurrency analysis becomes a target
- medium ROI

### 10. PlanHeuristicAnalyzer

External source classes/files:

- `PerformanceStudio/src/PlanViewer.Core/Services/PlanAnalyzer.cs`
- optionally `phpstan-dba/src/Analyzer/QueryPlanAnalyzerMysql.php`

Detect:

- scan with predicate
- row estimate mismatch
- implicit conversion
- lookup-heavy plan patterns
- parallelism or memory heuristics when available from platform-specific `EXPLAIN`

Current Doctrine Doctor coverage:

- partially covered by `src/Analyzer/Performance/MissingIndexAnalyzer.php`
- partially covered by `src/Analyzer/Performance/SlowQueryAnalyzer.php`
- not covered as a broad execution-plan heuristic layer

Recommendation:

- implement later as an evolution of current explain-based analysis
- strategically strong, but more complex

## Additional SlowQL-Inspired Analyzer Ideas

The list below contains more analyzer opportunities from `slowql`. For each one, the external source and the current Doctrine Doctor coverage are stated explicitly.

### Performance

#### ORPredicateOptimizationAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/indexing.py` class `OrOnIndexedColumnsRule`
- `slowql/src/slowql/rules/performance/indexing.py` class `NonSargableOrConditionRule`

Detect:

- `WHERE ... OR ...`
- OR conditions likely to defeat index usage

Current Doctrine Doctor coverage:

- not covered directly

Recommendation:

- possible analyzer, but lower priority because false positives can be high

#### CompositeIndexOrderAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/indexing.py` class `CompositeIndexOrderViolationRule`

Detect:

- filters using secondary composite-index columns without the leading column

Current Doctrine Doctor coverage:

- not covered directly

Recommendation:

- useful only if tied to schema metadata or explain output

#### DistinctOnLargeSetAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/scanning.py` class `DistinctOnLargeSetRule`

Detect:

- `DISTINCT` on broad queries
- especially on slow or join-heavy queries

Current Doctrine Doctor coverage:

- not covered directly

Recommendation:

- better as a contextual analyzer than a standalone rule

#### ExistsSelectStarAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/quality/style.py` class `WildcardInColumnListRule`

Detect:

- `EXISTS (SELECT * FROM ...)`

Current Doctrine Doctor coverage:

- not covered directly
- partially adjacent to the proposed `SelectStarAnalyzer`

Recommendation:

- easy low-severity analyzer or enhancement to `SelectStarAnalyzer`

#### LargeUnbatchedOperationAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/batching.py` class `LargeUnbatchedOperationRule`

Detect:

- large `UPDATE` or `DELETE` operations with no batching strategy

Current Doctrine Doctor coverage:

- partially covered by `src/Analyzer/Performance/BulkOperationAnalyzer.php`
- not covered as a dedicated raw-SQL batching heuristic

Recommendation:

- relevant mainly for raw SQL and batch jobs

#### MissingBatchSizeInLoopAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/performance/batching.py` class `MissingBatchSizeInLoopRule`

Detect:

- loop-based DML without `LIMIT` or `TOP`

Current Doctrine Doctor coverage:

- partially covered by `src/Analyzer/Performance/FlushInLoopAnalyzer.php`
- partially covered by `src/Analyzer/Performance/FlushInLoopAnalyzerModern.php`
- not covered for raw SQL batch-loop patterns

Recommendation:

- useful only if `doctrine-doctor` expands further into command/batch SQL heuristics

### Reliability

#### TOCTOUAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/reliability/race_conditions.py` class `TOCTOUPatternRule`

Detect:

- `IF EXISTS` / `IF NOT EXISTS` followed by a separate modifying statement

Current Doctrine Doctor coverage:

- not covered directly

Recommendation:

- strong correctness rule, but more relevant to stored procedures and scripts than standard Doctrine queries

#### MissingRollbackHandlerAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/reliability/transactions.py` class `MissingRollbackRule`

Detect:

- explicit transaction blocks that commit but do not show rollback handling

Current Doctrine Doctor coverage:

- partially covered conceptually by `src/Analyzer/Integrity/TransactionBoundaryAnalyzer.php`
- not covered as a raw SQL transaction-block analyzer

Recommendation:

- more applicable to SQL scripts than ORM-generated SQL

#### EmptyTransactionAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/reliability/transactions.py` class `EmptyTransactionRule`

Detect:

- transaction opened and closed with no real work

Current Doctrine Doctor coverage:

- not covered directly

Recommendation:

- niche, but easy if custom SQL scripts are analyzed later

### Quality

#### MissingSubqueryAliasAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/quality/style.py` class `MissingAliasRule`

Detect:

- `FROM (SELECT ...)` without alias

Current Doctrine Doctor coverage:

- not covered directly

Recommendation:

- useful for native SQL validation, lower priority than runtime performance analyzers

#### CommentedOutSqlAnalyzer

External source classes/files:

- `slowql/src/slowql/rules/quality/style.py` class `CommentedCodeRule`

Detect:

- commented-out SQL fragments inside custom SQL blocks

Current Doctrine Doctor coverage:

- not covered directly

Recommendation:

- low priority

## Already Covered Or Partially Covered Themes

These external ideas should be treated as overlap or enhancement, not as brand-new priorities.

### Already covered

- leading wildcard LIKE
  - external source:
    - `slowql/src/slowql/rules/performance/indexing.py` class `LeadingWildcardRule`
    - `PerformanceStudio/src/PlanViewer.Core/Services/PlanAnalyzer.cs` regex `LeadingWildcardLikeRegex`
  - current coverage:
    - `src/Analyzer/Performance/IneffectiveLikeAnalyzer.php`

- too many joins
  - external source:
    - `slowql/src/slowql/rules/performance/joins.py` class `TooManyJoinsRule`
  - current coverage:
    - `src/Analyzer/Performance/JoinOptimizationAnalyzer.php`

- suboptimal left join usage
  - external source:
    - `slowql/src/slowql/rules/performance/joins.py` class `LeftJoinWithIsNotNullRule`
  - current coverage:
    - `src/Analyzer/Performance/JoinOptimizationAnalyzer.php`

- cartesian product
  - external source:
    - `slowql/src/slowql/rules/performance/joins.py` class `CartesianProductRule`
  - current coverage:
    - `src/Analyzer/Performance/CartesianProductAnalyzer.php`

### Partially covered

- `ORDER BY` without `LIMIT`
  - external source:
    - `slowql/src/slowql/rules/performance/scanning.py` class `UnboundedSelectRule`
    - adjacent family in `slowql/src/slowql/rules/performance/scanning.py`
  - current coverage:
    - `src/Analyzer/Performance/OrderByWithoutLimitAnalyzer.php`

- date function misuse such as `YEAR(...)`
  - external source:
    - `slowql/src/slowql/rules/performance/indexing.py` class `FunctionOnIndexedColumnRule`
    - `PerformanceStudio/src/PlanViewer.Core/Services/PlanAnalyzer.cs` regex `FunctionInPredicateRegex`
  - current coverage:
    - existing year/date optimization analyzers, especially `YearFunctionOptimizationAnalyzer`

- bulk and batching concerns
  - external source:
    - `slowql/src/slowql/rules/performance/batching.py` class `LargeUnbatchedOperationRule`
    - `slowql/src/slowql/rules/performance/batching.py` class `MissingBatchSizeInLoopRule`
  - current coverage:
    - `src/Analyzer/Performance/BulkOperationAnalyzer.php`
    - `src/Analyzer/Performance/FlushInLoopAnalyzer.php`
    - `src/Analyzer/Performance/FlushInLoopAnalyzerModern.php`

- transaction and locking concerns
  - external source:
    - `slowql/src/slowql/rules/reliability/race_conditions.py` class `ReadModifyWriteLockingRule`
    - `slowql/src/slowql/rules/reliability/transactions.py` class `MissingRollbackRule`
  - current coverage:
    - `src/Analyzer/Integrity/TransactionBoundaryAnalyzer.php`
    - `src/Analyzer/Integrity/DenormalizedAggregateWithoutLockingAnalyzer.php`

## Suggested Implementation Order

### Batch 1

- `PlaceholderMismatchAnalyzer`
- `SelectStarAnalyzer`
- `DeepOffsetPaginationAnalyzer`

### Batch 2

- `NotInSubqueryAnalyzer`
- `ImplicitTypeConversionAnalyzer`
- `FunctionOnPredicateColumnAnalyzer`

### Batch 3

- `ReadModifyWriteWithoutLockAnalyzer`
- `DbalRawQueryValidationAnalyzer`
- `PlanHeuristicAnalyzer`

## Recommendation

If only one analyzer is implemented next, it should be:

- `PlaceholderMismatchAnalyzer`

If a small first batch is implemented, the best trio is:

- `PlaceholderMismatchAnalyzer`
- `SelectStarAnalyzer`
- `DeepOffsetPaginationAnalyzer`

## Final Priority

This is the most actionable rollout view for `doctrine-doctor`.

### Build Now

These have strong pertinence, weak or no current implementation, and low enough ambiguity to ship as real analyzers soon.

| Analyzer | Status | Pertinence score | Why now |
| --- | --- | --- | --- |
| `PlaceholderMismatchAnalyzer` | `partially covered` | `10/10` | High-signal DBAL/runtime gap. Existing QueryBuilder checks do not cover raw SQL placeholder mismatches well. |
| `DeepOffsetPaginationAnalyzer` | `not implemented` | `9/10` | Clear performance smell, easy to explain, complementary to current pagination/order analyzers. |
| `ImplicitTypeConversionAnalyzer` | `not implemented` | `9/10` | Strong real-world performance impact and currently absent. |
| `DbalRawQueryValidationAnalyzer` | `partially covered` | `9/10` | Consolidates several raw SQL safety/quality gaps already touched separately. |
| `NotInSubqueryAnalyzer` | `not implemented` | `8/10` | Good correctness/performance rule with straightforward remediation to `NOT EXISTS`. |
| `SelectStarAnalyzer` | `partially covered` | `8/10` | Common runtime SQL smell, currently only implicit in other analyzers and suggestions. |

### Improve Existing

These are already represented in the codebase, but the external sources suggest a broader or sharper version rather than a brand-new analyzer.

| Analyzer | Status | Pertinence score | Existing local basis | Recommended move |
| --- | --- | --- | --- | --- |
| `FunctionOnPredicateColumnAnalyzer` | `partially covered` | `8/10` | `YearFunctionOptimizationAnalyzer` | Broaden from date functions to generic function-wrapped predicates like `LOWER`, `COALESCE`, `CAST`, `ISNULL`. |
| `PlanHeuristicAnalyzer` | `partially covered` | `8/10` | `MissingIndexAnalyzer`, `SlowQueryAnalyzer` | Evolve current explain/perf heuristics rather than adding a disconnected analyzer. |
| `UnboundedSelectAnalyzer` | `partially covered` | `7/10` | `FindAllAnalyzer`, `OrderByWithoutLimitAnalyzer` | Extend coverage to native SQL and broader non-ORM fetch patterns. |
| `LargeUnbatchedOperationAnalyzer` | `partially covered` | `7/10` | `BulkOperationAnalyzer` | Refine toward single large raw SQL update/delete detection. |
| `MissingBatchSizeInLoopAnalyzer` | `partially covered` | `6/10` | `FlushInLoopAnalyzer`, `FlushInLoopAnalyzerModern` | Extend from ORM flush loops to raw SQL batch-loop heuristics only if batch-command scope matters. |
| `DistinctOnLargeSetAnalyzer` | `partially covered` | `6/10` | `SlowQueryAnalyzer` optimization hints | Upgrade from hinting to explicit detection only if noise can be controlled. |
| `ReadModifyWriteWithoutLockAnalyzer` | `partially covered` | `6/10` | `TransactionBoundaryAnalyzer`, `MissingVersionFieldForConcurrencyAnalyzer`, `DenormalizedAggregateWithoutLockingAnalyzer` | Add only if raw SQL concurrency analysis becomes an explicit goal. |
| `MissingRollbackHandlerAnalyzer` | `partially covered` | `5/10` | `TransactionBoundaryAnalyzer` | More of a script/transaction-block analysis extension than a profiler analyzer. |

### Ignore For Now

These are either low-ROI for the current product shape, too script-oriented, too noisy, or already sufficiently covered by adjacent analyzers.

| Analyzer | Status | Pertinence score | Why ignore for now |
| --- | --- | --- | --- |
| `ORPredicateOptimizationAnalyzer` | `not implemented` | `5/10` | Risk of false positives is high without schema/plan context. |
| `CompositeIndexOrderAnalyzer` | `not implemented` | `6/10` | Useful only with richer metadata/explain coupling; too heuristic alone. |
| `ExistsSelectStarAnalyzer` | `not implemented` | `6/10` | Valid micro-rule, but low leverage compared with `SelectStarAnalyzer`. |
| `TOCTOUAnalyzer` | `not implemented` | `4/10` | More relevant to stored procedures/scripts than Doctrine runtime query profiling. |
| `EmptyTransactionAnalyzer` | `not implemented` | `3/10` | Very niche for the current bundle scope. |
| `MissingSubqueryAliasAnalyzer` | `not implemented` | `5/10` | More syntax-lint territory than runtime Doctrine analysis. |
| `CommentedOutSqlAnalyzer` | `not implemented` | `2/10` | Very low leverage for the current product. |

### Already Good Enough

These external ideas map to analyzers that already exist locally, so they should not be re-added.

| External idea | Local implementation |
| --- | --- |
| Leading wildcard LIKE | `src/Analyzer/Performance/IneffectiveLikeAnalyzer.php` |
| Too many joins | `src/Analyzer/Performance/JoinOptimizationAnalyzer.php` |
| Left join used suboptimally | `src/Analyzer/Performance/JoinOptimizationAnalyzer.php` |
| Cartesian product | `src/Analyzer/Performance/CartesianProductAnalyzer.php` |

## Second SlowQL Pass

This section captures additional ideas found during a wider exploration of `slowql`, beyond the first shortlist.

### Promising new ideas

| Analyzer idea | External source class/file | Verified local status | Pertinence score | Why it is interesting |
| --- | --- | --- | --- | --- |
| `PaginationWithoutOrderByAnalyzer` | `slowql/src/slowql/rules/quality/testing.py` class `OrderByMissingForPaginationRule` | `not implemented` | `9/10` | Very relevant for Doctrine and DBAL. `LIMIT/OFFSET` without stable ordering creates duplicate/missing rows across pages. This is a strong runtime-quality rule. |
| `NonIdempotentInsertAnalyzer` | `slowql/src/slowql/rules/reliability/idempotency.py` class `NonIdempotentInsertRule` | `not implemented` | `7/10` | Interesting for message-driven or retry-heavy applications. Useful when applications re-run writes after transport failure. |
| `NonIdempotentUpdateAnalyzer` | `slowql/src/slowql/rules/reliability/idempotency.py` class `NonIdempotentUpdateRule` | `not implemented` | `7/10` | Good complement to concurrency analyzers. Especially relevant for `count = count + 1` style updates without version checks. |
| `NonDeterministicQueryAnalyzer` | `slowql/src/slowql/rules/quality/testing.py` class `NonDeterministicQueryRule` | `not implemented` | `6/10` | Could catch `NOW()`, `RAND()`, `CURRENT_TIMESTAMP` in query logic. More of a reproducibility/testability rule than a pure runtime performance rule, but still useful. |
| `DuplicateQueryFingerprintAnalyzer` | `slowql/src/slowql/rules/quality/dead_sql.py` class `DuplicateQueryRule` | `partially covered` | `6/10` | Interesting if oriented toward codebase/query-definition duplication. Runtime repetition is already partly handled by `QueryCachingOpportunityAnalyzer`, but structural duplicate-query detection is still different. |
| `UnsafeWriteAnalyzer` | `slowql/src/slowql/rules/reliability/data_safety.py` class `UnsafeWriteRule` | `partially covered` | `5/10` | Doctrine Doctor already covers nearby space via batch/transaction analyzers, but a dedicated catastrophic `UPDATE/DELETE` without `WHERE` rule could still be useful for raw SQL. |
| `MissingCoveringIndexOpportunityAnalyzer` | `slowql/src/slowql/rules/cost/indexing.py` class `MissingCoveringIndexOpportunityRule` | `not implemented` | `6/10` | Interesting if you want to push `MissingIndexAnalyzer` beyond “index absent” toward “index exists but non-covering”. More complex, but valuable. |

### New ideas that are less compelling for now

| Analyzer idea | External source class/file | Verified local status | Pertinence score | Why lower priority |
| --- | --- | --- | --- | --- |
| `InsertIgnoreAnalyzer` | `slowql/src/slowql/rules/reliability/data_safety.py` class `InsertIgnoreRule` | `not implemented` | `4/10` | Useful but MySQL-specific and closer to SQL linting than core Doctrine runtime diagnostics. |
| `ReplaceIntoAnalyzer` | `slowql/src/slowql/rules/reliability/data_safety.py` class `ReplaceIntoRule` | `not implemented` | `4/10` | Same issue: valid rule, but narrower than the current highest-value backlog. |
| `TruncateWithoutTransactionAnalyzer` | `slowql/src/slowql/rules/reliability/data_safety.py` class `TruncateWithoutTransactionRule` | `not implemented` | `3/10` | More migration/script oriented than profiler/runtime oriented. |
| `DuplicateIndexSignalAnalyzer` | `slowql/src/slowql/rules/cost/indexing.py` class `DuplicateIndexSignalRule` | `not implemented` | `4/10` | Better suited to schema/migration auditing than request-time Doctrine profiling. |
| `OverIndexedTableSignalAnalyzer` | `slowql/src/slowql/rules/cost/indexing.py` class `OverIndexedTableSignalRule` | `not implemented` | `3/10` | Also more schema-audit territory than runtime query analysis. |

### Impact on final priority

After this second pass, the strongest additions to the backlog are:

1. `PaginationWithoutOrderByAnalyzer`
2. `NonIdempotentInsertAnalyzer`
3. `NonIdempotentUpdateAnalyzer`
4. `MissingCoveringIndexOpportunityAnalyzer`

The most immediately valuable one is probably:

- `PaginationWithoutOrderByAnalyzer`

Reason:

- very relevant to Doctrine paginator usage and DBAL pagination
- currently not implemented
- easy to explain
- likely lower false-positive rate than many other second-pass ideas

## Absolute Top 10

This is the final merged ranking after:

- first-pass repository review
- exact local implementation verification
- second-pass exploration of additional `slowql` rules

Ordered from highest value to lowest value for `doctrine-doctor`.

| Rank | Analyzer | Status | Pertinence score | Source |
| --- | --- | --- | --- | --- |
| 1 | `PlaceholderMismatchAnalyzer` | `partially covered` | `10/10` | `phpstan-dba/src/QueryReflection/PlaceholderValidation.php` |
| 2 | `DeepOffsetPaginationAnalyzer` | `not implemented` | `9/10` | `slowql/src/slowql/rules/performance/indexing.py` class `DeepOffsetPaginationRule` |
| 3 | `ImplicitTypeConversionAnalyzer` | `not implemented` | `9/10` | `slowql/src/slowql/rules/performance/indexing.py` class `ImplicitTypeConversionRule` |
| 4 | `DbalRawQueryValidationAnalyzer` | `partially covered` | `9/10` | `phpstan-dba/src/Rules/SyntaxErrorInQueryMethodRule.php`, `phpstan-dba/src/Rules/SyntaxErrorInPreparedStatementMethodRule.php`, `sqlvet/pkg/vet/vet.go` |
| 5 | `PaginationWithoutOrderByAnalyzer` | `not implemented` | `9/10` | `slowql/src/slowql/rules/quality/testing.py` class `OrderByMissingForPaginationRule` |
| 6 | `NotInSubqueryAnalyzer` | `not implemented` | `8/10` | `slowql/src/slowql/rules/performance/scanning.py` classes `NotInSubqueryRule`, `NotInNullableSubqueryRule` |
| 7 | `SelectStarAnalyzer` | `partially covered` | `8/10` | `slowql/src/slowql/rules/performance/scanning.py` class `SelectStarRule` |
| 8 | `FunctionOnPredicateColumnAnalyzer` | `partially covered` | `8/10` | `slowql/src/slowql/rules/performance/indexing.py` class `FunctionOnIndexedColumnRule`, `PerformanceStudio/src/PlanViewer.Core/Services/PlanAnalyzer.cs` |
| 9 | `PlanHeuristicAnalyzer` | `partially covered` | `8/10` | `PerformanceStudio/src/PlanViewer.Core/Services/PlanAnalyzer.cs`, `phpstan-dba/src/Analyzer/QueryPlanAnalyzerMysql.php` |
| 10 | `NonIdempotentInsertAnalyzer` | `not implemented` | `7/10` | `slowql/src/slowql/rules/reliability/idempotency.py` class `NonIdempotentInsertRule` |

### Near misses

These did not make the final top 10, but remain worth considering later:

| Analyzer | Status | Pertinence score | Source |
| --- | --- | --- | --- |
| `NonIdempotentUpdateAnalyzer` | `not implemented` | `7/10` | `slowql/src/slowql/rules/reliability/idempotency.py` class `NonIdempotentUpdateRule` |
| `UnboundedSelectAnalyzer` | `partially covered` | `7/10` | `slowql/src/slowql/rules/performance/scanning.py` class `UnboundedSelectRule` |
| `LargeUnbatchedOperationAnalyzer` | `partially covered` | `7/10` | `slowql/src/slowql/rules/performance/batching.py` class `LargeUnbatchedOperationRule` |
| `MissingCoveringIndexOpportunityAnalyzer` | `not implemented` | `6/10` | `slowql/src/slowql/rules/cost/indexing.py` class `MissingCoveringIndexOpportunityRule` |
| `ReadModifyWriteWithoutLockAnalyzer` | `partially covered` | `6/10` | `slowql/src/slowql/rules/reliability/race_conditions.py` class `ReadModifyWriteLockingRule` |

### Best first implementation batch

If you want the strongest first implementation batch from this final ranking, build these first:

1. `PlaceholderMismatchAnalyzer`
2. `DeepOffsetPaginationAnalyzer`
3. `ImplicitTypeConversionAnalyzer`
4. `PaginationWithoutOrderByAnalyzer`

If you want a slightly broader first milestone, add:

5. `DbalRawQueryValidationAnalyzer`
6. `NotInSubqueryAnalyzer`

## Runtime Feasibility Filter

This section is the most important correction after a stricter pass.

Doctrine Doctor is not primarily a static SQL linter. In the current architecture, analyzers mostly receive:

- executed SQL text
- bound parameters
- execution time
- backtrace
- optional row count

That means some ideas that are excellent in `phpstan-dba` or `slowql` lose value here because they are designed to catch problems before execution. If a query never executes successfully, it often never becomes useful profiler input.

### High utility with current runtime data

These map well to the actual data available in `QueryData` and can be detected reliably on executed queries.

| Analyzer | Utility after runtime filter | Why it still fits |
| --- | --- | --- |
| `DeepOffsetPaginationAnalyzer` | `very high` | Needs only SQL text. Works perfectly at runtime. |
| `PaginationWithoutOrderByAnalyzer` | `very high` | Needs only SQL text. Strong signal for pagination bugs. |
| `NotInSubqueryAnalyzer` | `high` | Needs only SQL text. Good correctness/performance signal. |
| `SelectStarAnalyzer` | `high` | Needs only SQL text and optionally timing/join context. |
| `FunctionOnPredicateColumnAnalyzer` | `high` | Needs only SQL text. Strong extension of existing date-function logic. |
| `PlanHeuristicAnalyzer` | `high` | Matches the product shape because Doctrine Doctor already does runtime/explain-style analysis. |
| `ImplicitTypeConversionAnalyzer` | `medium` | Feasible from SQL text, but accuracy depends on heuristics unless enriched by schema metadata or explain output. |
| `NonIdempotentUpdateAnalyzer` | `medium` | Can detect retry-sensitive `SET count = count + 1` patterns in executed SQL. |

### Lower utility than they first appeared

These are not bad ideas, but their value drops in a runtime-profiler product.

| Analyzer | Utility after runtime filter | Why it drops |
| --- | --- | --- |
| `PlaceholderMismatchAnalyzer` | `medium to low` | Missing placeholders often fail before successful execution. Runtime profiler may see too few actionable cases compared with a static rule. |
| `DbalRawQueryValidationAnalyzer` | `medium to low` | Syntax-validation style rules are much stronger in static analysis than after the query already ran. |
| `NonIdempotentInsertAnalyzer` | `medium` | Useful in retry-heavy systems, but less universal than pagination and query-shape analyzers. |
| `UnsafeWriteAnalyzer` | `medium` | Potentially useful for raw SQL, but many catastrophic writes may not appear in normal dev-profiler flows often enough. |
| `MissingCoveringIndexOpportunityAnalyzer` | `medium to low` | Interesting, but much harder to make reliable without deeper schema/index metadata and explain integration. |

### Revised practical top 10

This ranking is stricter than the earlier “idea quality” ranking. It optimizes for:

1. usefulness in a runtime profiler
2. reliability with currently captured data
3. implementation payoff

| Rank | Analyzer | Runtime utility | Notes |
| --- | --- | --- | --- |
| 1 | `DeepOffsetPaginationAnalyzer` | `very high` | Clear, useful, easy runtime rule. |
| 2 | `PaginationWithoutOrderByAnalyzer` | `very high` | Excellent signal for real application bugs. |
| 3 | `NotInSubqueryAnalyzer` | `high` | Strong SQL correctness/performance rule. |
| 4 | `SelectStarAnalyzer` | `high` | Easy to detect and broadly useful in DBAL/native SQL. |
| 5 | `FunctionOnPredicateColumnAnalyzer` | `high` | Natural extension of existing analyzer family. |
| 6 | `PlanHeuristicAnalyzer` | `high` | Very aligned with Doctrine Doctor’s runtime identity. |
| 7 | `ImplicitTypeConversionAnalyzer` | `medium` | Valuable, but should probably be schema-aware or explain-assisted. |
| 8 | `NonIdempotentUpdateAnalyzer` | `medium` | Good if you want more write-safety analysis. |
| 9 | `PlaceholderMismatchAnalyzer` | `medium to low` | Better than nothing, but less ideal than static-analysis counterparts. |
| 10 | `DbalRawQueryValidationAnalyzer` | `medium to low` | Worth considering only if scoped to runtime-observable raw SQL anti-patterns, not syntax linting. |

### Best first implementation batch after utility correction

If the goal is maximum practical value for the current product, the best first batch is now:

1. `DeepOffsetPaginationAnalyzer`
2. `PaginationWithoutOrderByAnalyzer`
3. `NotInSubqueryAnalyzer`
4. `SelectStarAnalyzer`

Second batch:

5. `FunctionOnPredicateColumnAnalyzer`
6. `PlanHeuristicAnalyzer`
7. `ImplicitTypeConversionAnalyzer`

## Third SlowQL Pass

This pass was stricter and intentionally rejected most ideas that look good in a linter but weak in a runtime profiler.

### Worth keeping after the third pass

| Analyzer idea | External source class/file | Verified local status | Pertinence score | Runtime usefulness |
| --- | --- | --- | --- | --- |
| `PaginationWithoutOrderByAnalyzer` | `slowql/src/slowql/rules/quality/testing.py` class `OrderByMissingForPaginationRule` | `not implemented` | `9/10` | Still one of the best ideas. It survives every pass because it is easy to detect from executed SQL and maps to real application bugs. |
| `NonIdempotentUpdateAnalyzer` | `slowql/src/slowql/rules/reliability/idempotency.py` class `NonIdempotentUpdateRule` | `not implemented` | `7/10` | More convincing than `NonIdempotentInsertAnalyzer` for Doctrine Doctor because executed `UPDATE ... SET count = count + 1` patterns are observable at runtime and pair well with concurrency warnings. |
| `LongRunningQueryRiskAnalyzer` | `slowql/src/slowql/rules/reliability/timeouts.py` class `LongRunningQueryRiskRule` | `partially covered` | `5/10` | Interesting only as an enhancement to existing slow/complex query analysis, not as a standalone top-priority analyzer. |

### Ideas rejected after the third pass

These are valid `slowql` rules, but not strong enough for the current runtime-centric product.

| Analyzer idea | External source class/file | Why rejected |
| --- | --- | --- |
| `GodQueryAnalyzer` | `slowql/src/slowql/rules/quality/complexity.py` class `GodQueryRule` | Too subjective and noisy for a profiler. Query complexity alone is not a reliable runtime problem. |
| `ExcessiveSubqueryNestingAnalyzer` | `slowql/src/slowql/rules/quality/complexity.py` class `ExcessiveSubqueryNestingRule` | More readability/linting than actionable runtime analysis. |
| `ExcessiveCaseNestingAnalyzer` | `slowql/src/slowql/rules/quality/complexity.py` class `ExcessiveCaseNestingRule` | Mostly SQL maintainability lint, not a strong Doctrine Doctor runtime finding. |
| `LongQueryLineCountAnalyzer` | `slowql/src/slowql/rules/quality/complexity.py` class `LongQueryRule` | Line count is a weak signal in generated SQL and ORM-heavy contexts. |
| `HardcodedDateRule` | `slowql/src/slowql/rules/quality/modern_sql.py` class `HardcodedDateRule` | Too lint-oriented and not robustly useful once queries are already executed. |
| `UnionWithoutAllAnalyzer` | `slowql/src/slowql/rules/quality/modern_sql.py` class `UnionWithoutAllRule` | Could be useful in rare cases, but too speculative without schema/result semantics. |
| `ImplicitJoinSyntaxAnalyzer` | `slowql/src/slowql/rules/quality/modern_sql.py` class `ImplicitJoinRule` | Mostly style modernization, low value for the profiler. |
| `StaleReadRiskAnalyzer` | `slowql/src/slowql/rules/reliability/timeouts.py` class `StaleReadRiskRule` | Needs broader architectural context like replicas/read routing, which Doctrine Doctor does not currently know. |
| `MissingRetryLogicAnalyzer` | `slowql/src/slowql/rules/reliability/timeouts.py` class `MissingRetryLogicRule` | Better suited to code review or static workflow analysis than executed query profiling. |
| `SqlCalcFoundRowsAnalyzer` | `slowql/src/slowql/rules/quality/modern_sql.py` class `SqlCalcFoundRowsRule` | Valid but MySQL-specific and narrower than the stronger pagination analyzers already identified. |

### Net result after all SlowQL exploration

After repeatedly filtering for real utility in Doctrine Doctor's runtime architecture, the extra ideas from SlowQL that still feel genuinely valuable are:

1. `PaginationWithoutOrderByAnalyzer`
2. `NonIdempotentUpdateAnalyzer`
3. `DeepOffsetPaginationAnalyzer`
4. `NotInSubqueryAnalyzer`
5. `SelectStarAnalyzer`
6. `FunctionOnPredicateColumnAnalyzer`

Everything else is either:

- better handled by static analysis
- too dialect-specific
- too migration/script-oriented
- too noisy for a profiler panel

## Fourth SlowQL Pass

This pass focused on `slowql` security rules that can still be useful in a runtime profiler because they depend mainly on executed SQL text, not on unavailable build-time context.

### New security ideas that survived

| Analyzer idea | External source class/file | Verified local status | Pertinence score | Runtime usefulness |
| --- | --- | --- | --- | --- |
| `SchemaInformationDisclosureAnalyzer` | `slowql/src/slowql/rules/security/information.py` class `SchemaInformationDisclosureRule` | `not implemented` | `7/10` | Good runtime fit. Detects executed queries against `INFORMATION_SCHEMA`, `pg_catalog`, `SHOW TABLES`, `DESCRIBE`, etc. Useful because these often reflect reconnaissance or overexposed admin behavior in app code. |
| `DatabaseVersionDisclosureAnalyzer` | `slowql/src/slowql/rules/security/information.py` class `DatabaseVersionDisclosureRule` | `partially covered` | `5/10` | Runtime-detectable, but local code already uses `SELECT VERSION()` in infrastructure helpers. Useful only if carefully scoped to application queries, not internal platform detection. |
| `DataExfiltrationViaFileAnalyzer` | `slowql/src/slowql/rules/security/data_protection.py` class `DataExfiltrationViaFileRule` | `not implemented` | `6/10` | Strong signal when present: `INTO OUTFILE`, `LOAD_FILE()`, `LOAD DATA INFILE`, `BULK INSERT`, `COPY ... PROGRAM`. Rare but high-value security warning. |
| `SensitiveDataInErrorOutputAnalyzer` | `slowql/src/slowql/rules/security/logging.py` class `SensitiveDataInErrorOutputRule` | `partially covered` | `6/10` | Adjacent to `SensitiveDataExposureAnalyzer`, but different angle: focuses on executed SQL/error output patterns that leak sensitive values. Could be useful if scoped to raw SQL/stored-procedure style queries. |
| `AuditTrailManipulationAnalyzer` | `slowql/src/slowql/rules/security/logging.py` class `AuditTrailManipulationRule` | `not implemented` | `6/10` | Good signal if app SQL is deleting/truncating audit/history tables. Niche, but very security-relevant when it appears. |
| `RemoteDataAccessAnalyzer` | `slowql/src/slowql/rules/security/data_protection.py` class `RemoteDataAccessRule` | `not implemented` | `5/10` | Detects `OPENROWSET`, `OPENQUERY`, `dblink`, etc. Useful if Doctrine Doctor wants to flag lateral-movement/exfiltration primitives in executed SQL. |

### Security ideas rejected in this pass

| Analyzer idea | External source class/file | Why rejected |
| --- | --- | --- |
| `TimingAttackPatternAnalyzer` | `slowql/src/slowql/rules/security/information.py` class `TimingAttackPatternRule` | Too overlapping with existing injection detection and too broad for profiler usefulness. |
| `VerboseErrorMessageDisclosureAnalyzer` | `slowql/src/slowql/rules/security/information.py` class `VerboseErrorMessageDisclosureRule` | Better handled in application exception/logging review than by inspecting executed SQL only. |
| `LoadDataLocalInfileAnalyzer` | `slowql/src/slowql/rules/security/data_protection.py` class `LoadDataLocalInfileRule` | Valid but narrower than the broader `DataExfiltrationViaFileAnalyzer`. |
| `Snowflake` / `Redshift` credential copy rules | `slowql/src/slowql/rules/security/data_protection.py` | Too warehouse-specific for current Doctrine Doctor scope. |

### Net effect on the shortlist

The strongest extra ideas discovered in this fourth pass are:

1. `SchemaInformationDisclosureAnalyzer`
2. `DataExfiltrationViaFileAnalyzer`
3. `AuditTrailManipulationAnalyzer`

They are not above the best pagination/performance analyzers, but they are more promising than many of the late-stage quality or complexity rules explored earlier.

## Fifth SlowQL Pass

This pass continued into `schema`, `quality/schema_design`, and `security/configuration` rules with the same runtime-profiler filter.

### New ideas worth keeping

| Analyzer idea | External source class/file | Verified local status | Pertinence score | Runtime usefulness |
| --- | --- | --- | --- | --- |
| `SearchPathManipulationAnalyzer` | `slowql/src/slowql/rules/security/configuration.py` class `SearchPathManipulationRule` | `not implemented` | `6/10` | Good niche PostgreSQL security rule. Executed `SET search_path` is directly visible at runtime and can signal risky SQL behavior in apps using unqualified object names. |
| `SecurityDefinerWithoutSearchPathAnalyzer` | `slowql/src/slowql/rules/security/configuration.py` class `PgSecurityDefinerWithoutSearchPathRule` | `not implemented` | `5/10` | More useful if Doctrine Doctor ever inspects migration/procedure SQL, less useful for standard request SQL, but still a real security pattern. |
| `JsonbMissingIndexAnalyzer` | `slowql/src/slowql/rules/schema/missing_index.py` JSONB branch inside class `MissingIndexRule` | `partially covered` | `7/10` | Interesting specialization of missing-index analysis. Better than a generic “missing index” duplicate because JSONB/GIN advice is specific and hard to infer from simpler heuristics. |
| `MissingIndexOnForeignKeyAnalyzer` | `slowql/src/slowql/rules/quality/schema_design.py` class `LackOfIndexingOnForeignKeyRule` | `partially covered` | `6/10` | Could complement metadata-level Doctrine relation analysis if translated from DDL/schema logic to Doctrine metadata plus runtime query context. |

### Ideas rejected in this pass

| Analyzer idea | External source class/file | Why rejected |
| --- | --- | --- |
| `MissingPrimaryKeyAnalyzer` | `slowql/src/slowql/rules/quality/schema_design.py` class `MissingPrimaryKeyRule` | Mostly schema/DDL lint. Doctrine entities and metadata already make this less central for Doctrine Doctor. |
| `MissingForeignKeyAnalyzer` | `slowql/src/slowql/rules/quality/schema_design.py` class `MissingForeignKeyRule` | Better handled through Doctrine metadata modeling analyzers than through SQL-string style rules. |
| `UsingFloatForCurrencyRule` | `slowql/src/slowql/rules/quality/schema_design.py` class `UsingFloatForCurrencyRule` | Already strongly covered locally by money/decimal analyzers. |
| `HardcodedCredentialsRule` | `slowql/src/slowql/rules/security/configuration.py` class `HardcodedCredentialsRule` | Already covered locally by `HardcodedDatabaseCredentialsAnalyzer`. |
| `WeakSSLConfigRule` | `slowql/src/slowql/rules/security/configuration.py` class `WeakSSLConfigRule` | Better suited to connection/config analysis than runtime query profiling. |
| `DefaultCredentialUsageRule` | `slowql/src/slowql/rules/security/configuration.py` class `DefaultCredentialUsageRule` | Same reason: more config-level than runtime-SQL level. |
| `DangerousServerConfigRule` | `slowql/src/slowql/rules/security/configuration.py` class `DangerousServerConfigRule` | Real risk, but too SQL Server admin-script oriented for core Doctrine Doctor scope. |
| `OverprivilegedExecutionContextRule` | `slowql/src/slowql/rules/security/configuration.py` class `OverprivilegedExecutionContextRule` | More procedure/security-audit territory than request-time profiler analysis. |
| `OverlyPermissiveAccessRule` | `slowql/src/slowql/rules/security/configuration.py` class `OverlyPermissiveAccessRule` | Better detected from configuration and infrastructure, not application query traces. |

### Updated late-stage worthwhile additions from SlowQL

After this fifth pass, the most credible late additions from `slowql` are:

1. `SchemaInformationDisclosureAnalyzer`
2. `DataExfiltrationViaFileAnalyzer`
3. `AuditTrailManipulationAnalyzer`
4. `SearchPathManipulationAnalyzer`
5. `JsonbMissingIndexAnalyzer`

Among these, the two strongest are still:

- `SchemaInformationDisclosureAnalyzer`
- `DataExfiltrationViaFileAnalyzer`

Reason:

- they depend almost entirely on executed SQL text
- they produce security findings users immediately understand
- they do not require broad architectural assumptions to be useful

## Security-Focused Reweighting

If the product goal is also to make Doctrine Doctor a stronger SQL security panel during development, several ideas from the later SlowQL passes become more valuable than they would in a purely performance-focused ranking.

### Best security additions from SlowQL

| Analyzer | Source | Security fit | Notes |
| --- | --- | --- | --- |
| `SchemaInformationDisclosureAnalyzer` | `slowql/src/slowql/rules/security/information.py` class `SchemaInformationDisclosureRule` | `high` | Very understandable finding for developers. Detects app SQL that exposes or probes schema metadata. |
| `DataExfiltrationViaFileAnalyzer` | `slowql/src/slowql/rules/security/data_protection.py` class `DataExfiltrationViaFileRule` | `high` | Rare but high-impact. Strong signal if ever triggered. |
| `AuditTrailManipulationAnalyzer` | `slowql/src/slowql/rules/security/logging.py` class `AuditTrailManipulationRule` | `high` | Good if your users run raw SQL or custom data-maintenance code. |
| `SearchPathManipulationAnalyzer` | `slowql/src/slowql/rules/security/configuration.py` class `SearchPathManipulationRule` | `medium` | PostgreSQL-specific but quite meaningful where relevant. |
| `SensitiveDataInErrorOutputAnalyzer` | `slowql/src/slowql/rules/security/logging.py` class `SensitiveDataInErrorOutputRule` | `medium` | Complements `SensitiveDataExposureAnalyzer` from a SQL/error-output angle. |
| `RemoteDataAccessAnalyzer` | `slowql/src/slowql/rules/security/data_protection.py` class `RemoteDataAccessRule` | `medium` | Good for catching SQL primitives that can pivot to remote data sources. |

### Revised security shortlist

If security is explicitly a priority for Doctrine Doctor, the best security-oriented batch from SlowQL is:

1. `SchemaInformationDisclosureAnalyzer`
2. `DataExfiltrationViaFileAnalyzer`
3. `AuditTrailManipulationAnalyzer`
4. `SensitiveDataInErrorOutputAnalyzer`

Optional PostgreSQL-focused add-ons:

5. `SearchPathManipulationAnalyzer`
6. `SecurityDefinerWithoutSearchPathAnalyzer`

### Practical conclusion

With this security preference stated explicitly, these analyzers should no longer be treated as mere late-stage curiosities. They are valid product candidates for Doctrine Doctor because:

- they surface risky SQL during normal development workflows
- they are easy to explain in a profiler panel
- they reinforce the bundle's security positioning, not just performance/integrity

## Verified Local Status

This section is a stricter pass against the current local codebase.

Legend:

- `implemented`: a dedicated analyzer already exists
- `partially covered`: related logic exists, but not as the same analyzer scope
- `not implemented`: no meaningful implementation found
- `pertinence score`: score from `1/10` to `10/10` for Doctrine Doctor specifically

### Main proposals

| Proposal | Verified status | Pertinence score | Notes |
| --- | --- | --- |
| `PlaceholderMismatchAnalyzer` | `partially covered` | `10/10` | `src/Analyzer/Integrity/QueryBuilderBestPracticesAnalyzer.php` detects missing `setParameter()` in QueryBuilder flows, but there is no dedicated raw SQL / DBAL placeholder-count or extra-parameter analyzer. |
| `SelectStarAnalyzer` | `partially covered` | `8/10` | `src/Analyzer/Integrity/PartialObjectAnalyzer.php` treats `SELECT *` as a signal for full entity loading, but there is no dedicated analyzer whose goal is “avoid SELECT *”. |
| `NotInSubqueryAnalyzer` | `not implemented` | `8/10` | No dedicated analyzer found for `NOT IN (SELECT ...)` or nullable-subquery semantics. |
| `DeepOffsetPaginationAnalyzer` | `not implemented` | `9/10` | `src/Analyzer/Performance/OrderByWithoutLimitAnalyzer.php` knows `OFFSET` exists and skips those queries, but it does not flag deep offset pagination as a problem. |
| `ImplicitTypeConversionAnalyzer` | `not implemented` | `9/10` | No dedicated analyzer found for literal/column type mismatch in predicates. |
| `FunctionOnPredicateColumnAnalyzer` | `partially covered` | `8/10` | `src/Analyzer/Performance/YearFunctionOptimizationAnalyzer.php` covers `YEAR()`, `MONTH()`, `DATE()` patterns, but not a general-purpose function-on-column analyzer such as `LOWER`, `COALESCE`, `CAST`, `ISNULL`, etc. |
| `DbalRawQueryValidationAnalyzer` | `partially covered` | `9/10` | `src/Analyzer/Security/SQLInjectionInRawQueriesAnalyzer.php` and `src/Analyzer/Integrity/QueryBuilderBestPracticesAnalyzer.php` cover parts of the space, but there is no unified DBAL raw-query validation analyzer. |
| `UnboundedSelectAnalyzer` | `partially covered` | `7/10` | `src/Analyzer/Performance/FindAllAnalyzer.php` and `src/Analyzer/Performance/OrderByWithoutLimitAnalyzer.php` cover common ORM forms, but not a general native-SQL unbounded select analyzer. |
| `ReadModifyWriteWithoutLockAnalyzer` | `partially covered` | `6/10` | `src/Analyzer/Integrity/TransactionBoundaryAnalyzer.php`, `src/Analyzer/Integrity/MissingVersionFieldForConcurrencyAnalyzer.php`, and `src/Analyzer/Integrity/DenormalizedAggregateWithoutLockingAnalyzer.php` cover concurrency/locking from other angles, but not this exact SQL pattern. |
| `PlanHeuristicAnalyzer` | `partially covered` | `8/10` | `src/Analyzer/Performance/MissingIndexAnalyzer.php` and `src/Analyzer/Performance/SlowQueryAnalyzer.php` cover selected plan/performance symptoms, but there is no broad execution-plan heuristic analyzer. |

### Additional SlowQL-inspired proposals

| Proposal | Verified status | Pertinence score | Notes |
| --- | --- | --- |
| `ORPredicateOptimizationAnalyzer` | `not implemented` | `5/10` | No dedicated analyzer found for `WHERE ... OR ...` optimization patterns. |
| `CompositeIndexOrderAnalyzer` | `not implemented` | `6/10` | No analyzer found for leading-column requirements of composite indexes. |
| `DistinctOnLargeSetAnalyzer` | `partially covered` | `6/10` | `src/Analyzer/Performance/SlowQueryAnalyzer.php` mentions `DISTINCT` in optimization hints, but there is no dedicated analyzer for expensive `DISTINCT` usage. |
| `ExistsSelectStarAnalyzer` | `not implemented` | `6/10` | No dedicated analyzer found for `EXISTS (SELECT *)`. |
| `LargeUnbatchedOperationAnalyzer` | `partially covered` | `7/10` | `src/Analyzer/Performance/BulkOperationAnalyzer.php` detects repeated bulk-like update/delete patterns, but not the exact single-query “large unbatched operation” rule from SlowQL. |
| `MissingBatchSizeInLoopAnalyzer` | `partially covered` | `6/10` | `src/Analyzer/Performance/FlushInLoopAnalyzer.php` and `src/Analyzer/Performance/FlushInLoopAnalyzerModern.php` cover loop-related inefficiency on the ORM side, not raw SQL loop batching. |
| `TOCTOUAnalyzer` | `not implemented` | `4/10` | No dedicated analyzer found for `IF EXISTS` followed by modification race patterns. |
| `MissingRollbackHandlerAnalyzer` | `partially covered` | `5/10` | `src/Analyzer/Integrity/TransactionBoundaryAnalyzer.php` checks rollback/commit transaction hygiene from runtime query traces, but not as a SQL block structure analyzer. |
| `EmptyTransactionAnalyzer` | `not implemented` | `3/10` | No dedicated analyzer found for empty `BEGIN`/`COMMIT` blocks. |
| `MissingSubqueryAliasAnalyzer` | `not implemented` | `5/10` | No dedicated analyzer found for `FROM (SELECT ...)` without alias. |
| `CommentedOutSqlAnalyzer` | `not implemented` | `2/10` | No dedicated analyzer found for commented-out SQL fragments. |

### Overlap proposals already implemented

| External idea | Verified status | Pertinence score | Local implementation |
| --- | --- | --- |
| Leading wildcard LIKE | `implemented` | `9/10` | `src/Analyzer/Performance/IneffectiveLikeAnalyzer.php` |
| Too many joins | `implemented` | `8/10` | `src/Analyzer/Performance/JoinOptimizationAnalyzer.php` |
| Left join used suboptimally | `implemented` | `7/10` | `src/Analyzer/Performance/JoinOptimizationAnalyzer.php` |
| Cartesian product | `implemented` | `8/10` | `src/Analyzer/Performance/CartesianProductAnalyzer.php` |
