# Regex Documentation Report

Date: 2025-11-13 07:56:24
Undocumented patterns found: 36

## Patterns Needing Documentation

### Helper/MarkdownFormatter.php

**Line 82**
```
// Pattern: Simple pattern match: /^[-•]\s/
```
Pattern: `/^[-•]\s/`

**Line 140**
```
// Pattern: Extract inline code markdown
```
Pattern: `/`([^`]+)`/`


### ValueObject/SuggestionContentBlock.php

**Line 293**
```
// Pattern: Simple pattern match: /^[-•]\s+/
```
Pattern: `/^[-•]\s+/`


### Service/IssueDeduplicator.php

**Line 194**
```
// Pattern: Complex structure extraction (consider using parser)
```
Pattern: `/(?:entity|class|Entity)\s+["\`

**Line 207**
```
// Pattern: SQL query structure extraction
```
Pattern: `/FROM\s+(\w+)/i`


### Suggestion/BulkOperationSuggestion.php

**Line 274**
```
// Pattern: Simple pattern match: /^(tbl_|tb_)/
```
Pattern: `/^(tbl_|tb_)/`


### Analyzer/SlowQueryAnalyzer.php

**Line 96**
```
// Pattern: SQL query structure extraction
```
Pattern: `/(SELECT.*FROM.*WHERE.*\(.*SELECT)/i`

**Line 100**
```
// Pattern: Detect ORDER BY clause
```
Pattern: `/ORDER BY/i`

**Line 104**
```
// Pattern: Detect GROUP BY clause
```
Pattern: `/GROUP BY/i`

**Line 108**
```
// Pattern: Simple pattern match: /LIKE\s+[\
```
Pattern: `/LIKE\s+[\`

**Line 112**
```
// Pattern: SQL query structure extraction
```
Pattern: `/SELECT DISTINCT/i`


### Analyzer/JoinOptimizationAnalyzer.php

**Line 257**
```
// Pattern: Detect JOIN in SQL query
```
Pattern: `/\b(LEFT|INNER|RIGHT|OUTER)?\s*JOIN\b/i`


### Analyzer/QueryCachingOpportunityAnalyzer.php

**Line 324**
```
// Pattern: Match double-quoted strings
```
Pattern: `/"[^"]*"/`

**Line 361**
```
// Pattern: SQL JOIN detection/extraction
```
Pattern: `/\bJOIN\s+(?:\w+\.)?(`


### Analyzer/LazyLoadingAnalyzer.php

**Line 197**
```
// Pattern: Simple pattern match: /^get([A-Z]\w+)/
```
Pattern: `/^get([A-Z]\w+)/`


### Analyzer/SensitiveDataExposureAnalyzer.php

**Line 294**
```
// Pattern: Simple pattern match: /[\
```
Pattern: `/[\`

**Line 340**
```
// Pattern: Simple pattern match: /[\
```
Pattern: `/[\`


### Analyzer/SQLInjectionInRawQueriesAnalyzer.php

**Line 317**
```
// Pattern: Simple pattern match: /[\
```
Pattern: `/[\`

**Line 318**
```
// Pattern: Variable/interpolation detection
```
Pattern: `/\$\w+[\s]*\.[\s]*[\`

**Line 335**
```
// Pattern: Character validation/sanitization
```
Pattern: `/"[^"]*\$\w+[^"]*"/s`

**Line 336**
```
// Pattern: Detect WHERE clause in SQL
```
Pattern: `/SELECT|INSERT|UPDATE|DELETE|FROM|WHERE/i`

**Line 357**
```
// Pattern: Variable/interpolation detection
```
Pattern: `/\$\w+\s*=.*?\..*?;/s`

**Line 357**
```
// Pattern: Variable/interpolation detection
```
Pattern: `/\$\w+\s*\.=.*?;/s`

**Line 375**
```
// Pattern: Complex structure extraction (consider using parser)
```
Pattern: `/sprintf\s*\(\s*[\`

**Line 376**
```
// Pattern: Variable/interpolation detection
```
Pattern: `/\$_(GET|POST|REQUEST|COOKIE|SERVER)/i`

**Line 377**
```
// Pattern: Variable/interpolation detection
```
Pattern: `/\$request->get/i`


### Template/helpers.php

**Line 65**
```
// Pattern: Match non-alphanumeric/dash characters
```
Pattern: `/[^a-zA-Z0-9\-_]/`


### Suggestions/timestampable_timezone.php

**Line 104**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`

**Line 106**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`

**Line 110**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`


### Suggestions/blameable_non_nullable_created_by.php

**Line 96**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`

**Line 97**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`

**Line 100**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`


### Suggestions/soft_delete_nullable.php

**Line 91**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`

**Line 94**
```
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
```
Pattern: `/(?<!^)[A-Z]/`


### MySQL/MySQLAnalysisStrategy.php

**Line 733**
```
// Pattern: Match numeric values
```
Pattern: `/^[+-]\d{2}:\d{2}$/`


