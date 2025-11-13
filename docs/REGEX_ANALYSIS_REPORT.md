# Regex Pattern Analysis Report
Generated: 2025-11-13 07:54:55

## Summary

- **Simple patterns** (replaceable): 0
- **Complex patterns** (need parser): 49
- **Undocumented patterns**: 42 ⚠️
- **Documented patterns**: 119 ✅

## 🔧 Complex Patterns (Need Parser)

These patterns are complex and should use a proper parser:

- `bin/../src/ValueObject/Helper/MarkdownFormatter.php:139` - Pattern: `/\*\*([^*]+)\*\*/`
  → Review manually

- `bin/../src/ValueObject/Helper/MarkdownFormatter.php:140` - Pattern: `/`([^`]+)`/`
  → Review manually

- `bin/../src/ValueObject/SuggestionContentBlock.php:260` - Pattern: `/\*\*([^*]+)\*\*/`
  → Review manually

- `bin/../src/ValueObject/SuggestionContentBlock.php:263` - Pattern: `/`([^`]+)`/`
  → Review manually

- `bin/../src/ValueObject/SuggestionContentBlock.php:344` - Pattern: `/\*\*([^*]+)\*\*/`
  → Review manually

- `bin/../src/ValueObject/SuggestionContentBlock.php:346` - Pattern: `/`([^`]+)`/`
  → Review manually

- `bin/../src/Service/IssueDeduplicator.php:137` - Pattern: `/(\d+)\s+(?:queries?|executions?)/i`
  → Review manually

- `bin/../src/Service/IssueDeduplicator.php:188` - Pattern: `/(?:entity|class|Entity)\s+["\`
  → Review manually

- `bin/../src/Service/IssueDeduplicator.php:194` - Pattern: `/(?:entity|class|Entity)\s+["\`
  → Review manually

- `bin/../src/Service/IssueDeduplicator.php:201` - Pattern: `/(?:table|FROM|JOIN)\s+["`]?(\w+)["`]?/i`
  → Use SqlStructureExtractor::extractJoins()

- `bin/../src/DTO/IssueData.php:193` - Pattern: `/'(?:[^'\\\\]|\\\\.)*'/`
  → Review manually

- `bin/../src/Analyzer/SlowQueryAnalyzer.php:96` - Pattern: `/(SELECT.*FROM.*WHERE.*\(.*SELECT)/i`
  → Use SqlStructureExtractor

- `bin/../src/Analyzer/EntityManagerClearAnalyzer.php:54` - Pattern: `/(?:INSERT INTO|UPDATE|DELETE FROM)\s+([^\s(]+)/i`
  → Use SqlStructureExtractor

- `bin/../src/Analyzer/InsecureRandomAnalyzer.php:169` - Pattern: `/md5\s*\(\s*(rand|mt_rand|time|microtime)/i`
  → Review manually

- `bin/../src/Analyzer/NPlusOneAnalyzer.php:99` - Pattern: `/'(?:[^'\\\\]|\\\\.)*'/`
  → Review manually

- `bin/../src/Analyzer/NPlusOneAnalyzer.php:106` - Pattern: `/IN\s*\([^)]+\)/i`
  → Review manually

- `bin/../src/Analyzer/Helper/NamingConventionHelper.php:49` - Pattern: `/[^a-zA-Z0-9_]/`
  → Review manually

- `bin/../src/Analyzer/Helper/NamingConventionHelper.php:102` - Pattern: `/[^a-zA-Z0-9_]/`
  → Review manually

- `bin/../src/Analyzer/Helper/NamingConventionHelper.php:110` - Pattern: `/[^a-zA-Z0-9_]/`
  → Review manually

- `bin/../src/Analyzer/QueryCachingOpportunityAnalyzer.php:319` - Pattern: `/'[^']*'/`
  → Review manually

- `bin/../src/Analyzer/QueryCachingOpportunityAnalyzer.php:324` - Pattern: `/"[^"]*"/`
  → Review manually

- `bin/../src/Analyzer/QueryCachingOpportunityAnalyzer.php:330` - Pattern: `/IN\s*\([^)]+\)/i`
  → Review manually

- `bin/../src/Analyzer/QueryCachingOpportunityAnalyzer.php:357` - Pattern: `/\bFROM\s+(?:\w+\.)?(`
  → Use SqlStructureExtractor

- `bin/../src/Analyzer/QueryCachingOpportunityAnalyzer.php:361` - Pattern: `/\bJOIN\s+(?:\w+\.)?(`
  → Use SqlStructureExtractor::extractJoins()

- `bin/../src/Analyzer/RepositoryFieldValidationAnalyzer.php:118` - Pattern: `/t\d+_\.(\w+)\s*(?:=|IN|LIKE|>|<|>=|<=|<>|!=)/i`
  → Review manually

- `bin/../src/Analyzer/QueryBuilderBestPracticesAnalyzer.php:137` - Pattern: `/IN\s*\(\s*\)/i`
  → Review manually

- `bin/../src/Analyzer/CollectionInitializationAnalyzer.php:393` - Pattern: `/parent\s*::\s*__construct\s*\(/`
  → Review manually

- `bin/../src/Analyzer/DQLInjectionAnalyzer.php:162` - Pattern: `/'.*(?:UNION|OR\s+1\s*=\s*1|AND\s+1\s*=\s*1|--|\#|\/\*).*'/i`
  → Review manually

- `bin/../src/Analyzer/DQLInjectionAnalyzer.php:187` - Pattern: `/WHERE\s+[^=]+\s*=\s*'[^'?:]+'/i`
  → Review manually

- `bin/../src/Analyzer/DQLInjectionAnalyzer.php:193` - Pattern: `/(?:WHERE|AND|OR)\s+[^=]+\s*=\s*'[^']*'\s+(?:OR|AND)\s+/i`
  → Review manually

- `bin/../src/Analyzer/GetReferenceAnalyzer.php:273` - Pattern: `/FROM\s+([^\s]+)/i`
  → Use SqlStructureExtractor

- `bin/../src/Analyzer/SetMaxResultsWithCollectionJoinAnalyzer.php:115` - Pattern: `/\s(?:LEFT\s+JOIN|INNER\s+JOIN|JOIN)\s+/i`
  → Use SqlStructureExtractor::extractJoins()

- `bin/../src/Analyzer/SetMaxResultsWithCollectionJoinAnalyzer.php:124` - Pattern: `/^SELECT\s+(.*?)\s+FROM/i`
  → Use SqlStructureExtractor

- `bin/../src/Analyzer/SetMaxResultsWithCollectionJoinAnalyzer.php:183` - Pattern: `/JOIN\s+\w+\s+\w+_\s+ON\s+\w+_\.ID\s*=\s*\w+_\.(?:\w+_)?ID(?:\s+WHERE|\s+AND|\s+...`
  → Use SqlStructureExtractor::extractJoins()

- `bin/../src/Analyzer/HydrationAnalyzer.php:108` - Pattern: `/LIMIT\s+(?:(\d+)\s*,\s*)?(\d+)(?:\s+OFFSET\s+\d+)?/i`
  → Review manually

- `bin/../src/Analyzer/PartialObjectAnalyzer.php:164` - Pattern: `/'[^']*'/`
  → Review manually

- `bin/../src/Analyzer/PartialObjectAnalyzer.php:165` - Pattern: `/\"[^\"]*\"/`
  → Review manually

- `bin/../src/Analyzer/PartialObjectAnalyzer.php:236` - Pattern: `/FROM\s+([A-Z]\w+(?:\\[A-Z]\w+)*)/i`
  → Use SqlStructureExtractor

- `bin/../src/Analyzer/DTOHydrationAnalyzer.php:169` - Pattern: `/'[^']*'/`
  → Review manually

- `bin/../src/Analyzer/DTOHydrationAnalyzer.php:170` - Pattern: `/\"[^\"]*\"/`
  → Review manually

- `bin/../src/Analyzer/MissingIndexAnalyzer.php:476` - Pattern: `/(?:SCAN|SEARCH)\s+(\w+)/i`
  → Review manually

- `bin/../src/Analyzer/MissingIndexAnalyzer.php:639` - Pattern: `/'(?:[^'\\\\]|\\\\.)*'/`
  → Review manually

- `bin/../src/Analyzer/MissingIndexAnalyzer.php:645` - Pattern: `/IN\s*\([^)]+\)/i`
  → Review manually

- `bin/../src/Analyzer/SensitiveDataExposureAnalyzer.php:251` - Pattern: `/json_encode\s*\(\s*\$this\s*\)/i`
  → Review manually

- `bin/../src/Analyzer/SensitiveDataExposureAnalyzer.php:252` - Pattern: `/serialize\s*\(\s*\$this\s*\)/i`
  → Review manually

- `bin/../src/Analyzer/OrderByWithoutLimitAnalyzer.php:75` - Pattern: `/\b(?:LIMIT|OFFSET)\b/i`
  → Review manually

- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:335` - Pattern: `/"[^"]*\$\w+[^"]*"/s`
  → Review manually

- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:375` - Pattern: `/sprintf\s*\(\s*[\`
  → Review manually

- `bin/../src/Template/helpers.php:65` - Pattern: `/[^a-zA-Z0-9\-_]/`
  → Review manually

## ⚠️ Undocumented Patterns

These patterns lack documentation:

- `bin/../src/ValueObject/Helper/MarkdownFormatter.php:82` - Add comment explaining the pattern
- `bin/../src/ValueObject/Helper/MarkdownFormatter.php:118` - Add comment explaining the pattern
- `bin/../src/ValueObject/Helper/MarkdownFormatter.php:139` - Add comment explaining the pattern
- `bin/../src/ValueObject/Helper/MarkdownFormatter.php:140` - Add comment explaining the pattern
- `bin/../src/ValueObject/SuggestionContentBlock.php:293` - Add comment explaining the pattern
- `bin/../src/Service/IssueDeduplicator.php:137` - Add comment explaining the pattern
- `bin/../src/Service/IssueDeduplicator.php:194` - Add comment explaining the pattern
- `bin/../src/Service/IssueDeduplicator.php:207` - Add comment explaining the pattern
- `bin/../src/Collector/Helper/DatabaseInfoCollector.php:166` - Add comment explaining the pattern
- `bin/../src/Suggestion/BulkOperationSuggestion.php:274` - Add comment explaining the pattern
- `bin/../src/Analyzer/SlowQueryAnalyzer.php:96` - Add comment explaining the pattern
- `bin/../src/Analyzer/SlowQueryAnalyzer.php:100` - Add comment explaining the pattern
- `bin/../src/Analyzer/SlowQueryAnalyzer.php:104` - Add comment explaining the pattern
- `bin/../src/Analyzer/SlowQueryAnalyzer.php:108` - Add comment explaining the pattern
- `bin/../src/Analyzer/SlowQueryAnalyzer.php:112` - Add comment explaining the pattern
- `bin/../src/Analyzer/JoinOptimizationAnalyzer.php:257` - Add comment explaining the pattern
- `bin/../src/Analyzer/Helper/NamingConventionHelper.php:102` - Add comment explaining the pattern
- `bin/../src/Analyzer/Helper/NamingConventionHelper.php:110` - Add comment explaining the pattern
- `bin/../src/Analyzer/QueryCachingOpportunityAnalyzer.php:324` - Add comment explaining the pattern
- `bin/../src/Analyzer/QueryCachingOpportunityAnalyzer.php:361` - Add comment explaining the pattern
- `bin/../src/Analyzer/LazyLoadingAnalyzer.php:197` - Add comment explaining the pattern
- `bin/../src/Analyzer/SensitiveDataExposureAnalyzer.php:294` - Add comment explaining the pattern
- `bin/../src/Analyzer/SensitiveDataExposureAnalyzer.php:340` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:317` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:318` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:335` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:336` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:357` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:357` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:375` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:376` - Add comment explaining the pattern
- `bin/../src/Analyzer/SQLInjectionInRawQueriesAnalyzer.php:377` - Add comment explaining the pattern
- `bin/../src/Template/helpers.php:65` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/timestampable_timezone.php:104` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/timestampable_timezone.php:106` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/timestampable_timezone.php:110` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/blameable_non_nullable_created_by.php:96` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/blameable_non_nullable_created_by.php:97` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/blameable_non_nullable_created_by.php:100` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/soft_delete_nullable.php:91` - Add comment explaining the pattern
- `bin/../src/Template/Suggestions/soft_delete_nullable.php:94` - Add comment explaining the pattern
- `bin/../src/Infrastructure/Strategy/MySQL/MySQLAnalysisStrategy.php:733` - Add comment explaining the pattern
