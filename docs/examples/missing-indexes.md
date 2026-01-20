---
layout: default
title: Missing Indexes
parent: Examples
nav_order: 2
---

# Missing Index Detection Examples

Learn how Doctrine Doctor identifies missing database indexes and improves query performance.

---

## What is a Missing Index?

A missing index forces the database to perform a **full table scan**, reading every row to find matches. This becomes exponentially slower as tables grow.

**Impact**:

- Development (100 rows): 5ms → acceptable
- Production (1M rows): 5,000ms → unacceptable

---

## How Detection Works

Doctrine Doctor uses `EXPLAIN` queries to analyze execution plans:

```sql
EXPLAIN SELECT * FROM article WHERE status = 'published'
```

**Signs of missing index**:

- `type: ALL` (full table scan)
- `rows: 1000000` (scanning many rows)
- `key: NULL` (no index used)

---

## Example 1: Simple WHERE Clause

### Problem Code

```php
<?php
// src/Repository/ArticleRepository.php

public function findPublished(): array
{
    return $this->createQueryBuilder('a')
        ->where('a.status = :status')
        ->setParameter('status', 'published')
        ->getQuery()
        ->getResult();
}
```

### Entity Without Index

```php
<?php
// src/Entity/Article.php

#[ORM\Entity]
class Article
{
    #[ORM\Column(type: 'string')]
    private string $status;  // No index!

    // ...
}
```

### Doctrine Doctor Detection

```text
🔴 Critical: Missing Index Detected

Query: SELECT * FROM article WHERE status = ?
Rows scanned: 50,000
Execution time: 234ms

Suggested index:
ALTER TABLE article ADD INDEX idx_status (status);
```

### Solution

Add index annotation:

```php
<?php

#[ORM\Entity]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
class Article
{
    #[ORM\Column(type: 'string')]
    private string $status;

    // ...
}
```

Or directly in MySQL:

```sql
ALTER TABLE article ADD INDEX idx_status (status);
```

### After Adding Index

```text
Query: SELECT * FROM article WHERE status = ?
Rows scanned: 8,500 (only matching rows)
Execution time: 12ms

Improvement: 95% faster
```

---

## Example 2: Composite Index (Multiple Columns)

### Problem Code

```php
<?php

public function findByUserAndStatus(User $user, string $status): array
{
    return $this->createQueryBuilder('a')
        ->where('a.author = :author')
        ->andWhere('a.status = :status')
        ->setParameter('author', $user)
        ->setParameter('status', $status)
        ->getQuery()
        ->getResult();
}
```

### Entity With Single Column Indexes

```php
<?php

#[ORM\Entity]
#[ORM\Index(name: 'idx_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
class Article
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    private User $author;

    #[ORM\Column(type: 'string')]
    private string $status;
}
```

### Doctrine Doctor Detection

```text
🟠 High: Suboptimal Index Usage

Query uses multiple WHERE conditions but only one index.
Consider a composite index for better performance.

Suggested composite index:
ALTER TABLE article ADD INDEX idx_author_status (author_id, status);
```

### Solution

```php
<?php

#[ORM\Entity]
#[ORM\Index(name: 'idx_author_status', columns: ['author_id', 'status'])]
class Article
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    private User $author;

    #[ORM\Column(type: 'string')]
    private string $status;
}
```

**Index Column Order Matters**:

- `(author_id, status)` - Good for queries filtering by author, or author+status
- `(status, author_id)` - Good for queries filtering by status, or status+author

Choose based on your most common query patterns.

---

## Example 3: Foreign Key Without Index

### Problem Code

```php
<?php

public function findByCategory(Category $category): array
{
    return $this->createQueryBuilder('a')
        ->where('a.category = :category')
        ->setParameter('category', $category)
        ->getQuery()
        ->getResult();
}
```

### Entity Without Foreign Key Index

```php
<?php

#[ORM\Entity]
class Article
{
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;  // Foreign key without index!
}
```

### Doctrine Doctor Detection

```text
🔴 Critical: Missing Foreign Key Index

Table: article
Foreign key: category_id
Rows scanned: 120,000

This severely impacts JOIN performance.

Suggested index:
ALTER TABLE article ADD INDEX idx_category (category_id);
```

### Solution

```php
<?php

#[ORM\Entity]
#[ORM\Index(name: 'idx_category', columns: ['category_id'])]
class Article
{
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;
}
```

!!! tip "Automatic Indexes"
    Some databases (like MySQL InnoDB) automatically create indexes on foreign keys. However, it's best to be explicit.

---

## Example 4: Sorting Without Index

### Problem Code

```php
<?php

public function findLatest(int $limit = 10): array
{
    return $this->createQueryBuilder('a')
        ->orderBy('a.publishedAt', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
```

### Entity Without Sort Index

```php
<?php

#[ORM\Entity]
class Article
{
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $publishedAt;  // No index!
}
```

### Doctrine Doctor Detection

```text
🟠 High: Missing Index for Sorting

Query uses ORDER BY published_at without index.
Causes filesort for large tables.

Suggested index:
ALTER TABLE article ADD INDEX idx_published_at (published_at);
```

### Solution

```php
<?php

#[ORM\Entity]
#[ORM\Index(name: 'idx_published_at', columns: ['published_at'])]
class Article
{
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $publishedAt;
}
```

---

## Example 5: Search Queries

### Problem Code

```php
<?php

public function search(string $term): array
{
    return $this->createQueryBuilder('a')
        ->where('a.title LIKE :term OR a.content LIKE :term')
        ->setParameter('term', '%' . $term . '%')
        ->getQuery()
        ->getResult();
}
```

### Doctrine Doctor Detection

```text
🟡 Medium: LIKE Query Without Index

Wildcard searches with leading % cannot use indexes efficiently.

Suggestions:
1. Use full-text search index for MySQL
2. Use PostgreSQL trigram indexes
3. Consider Elasticsearch for complex searches
```

### Solution Options

#### Option 1: MySQL Full-Text Index

```php
<?php

#[ORM\Entity]
#[ORM\Index(name: 'idx_fulltext', columns: ['title', 'content'], flags: ['fulltext'])]
class Article
{
    #[ORM\Column(type: 'string')]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $content;
}
```

```php
<?php

public function search(string $term): array
{
    return $this->createQueryBuilder('a')
        ->where('MATCH(a.title, a.content) AGAINST (:term IN BOOLEAN MODE) > 0')
        ->setParameter('term', $term)
        ->getQuery()
        ->getResult();
}
```

#### Option 2: PostgreSQL Trigram

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_title_trgm ON article USING gin (title gin_trgm_ops);
CREATE INDEX idx_content_trgm ON article USING gin (content gin_trgm_ops);
```

---

## Configuration

### Adjust Detection Thresholds

```yaml
doctrine_doctor:
    analyzers:
        missing_index:
            slow_query_threshold: 50      # Analyze queries slower than 50ms
            min_rows_scanned: 1000        # Flag if scanning 1000+ rows
            explain_queries: true         # Execute EXPLAIN (requires permissions)
```

### Disable for Specific Environments

```yaml
# config/packages/test/doctrine_doctor.yaml
doctrine_doctor:
    analyzers:
        missing_index:
            explain_queries: false  # Don't run EXPLAIN in tests
```

---

## Performance Impact Examples

### Small Table (< 1,000 rows)

```text
Without index: 5ms
With index: 2ms
Impact: Minimal
```

### Medium Table (10,000 - 100,000 rows)

```text
Without index: 50-200ms
With index: 5-10ms
Impact: 10-20x improvement
```

### Large Table (1M+ rows)

```text
Without index: 2,000-10,000ms
With index: 10-50ms
Impact: 100-500x improvement
```

---

## Best Practices

### When to Add Indexes

1. **Foreign keys** - Always index
2. **Frequent WHERE clauses** - High priority
3. **ORDER BY columns** - Important for sorting
4. **JOIN conditions** - Critical for performance

### When NOT to Add Indexes

1. **Rarely queried columns** - Overhead not worth it
2. **High write frequency tables** - Indexes slow down writes
3. **Small tables (< 1,000 rows)** - Full scans are fast enough
4. **Low cardinality columns** (e.g., boolean) - Limited benefit

### Index Maintenance

```sql
-- Check index usage (MySQL)
SHOW INDEX FROM article;

-- Analyze table statistics
ANALYZE TABLE article;

-- Remove unused indexes
DROP INDEX idx_unused ON article;
```

---

## Related Analyzers

- **SlowQueryAnalyzer**: Identifies slow queries that might need indexes
- **JoinOptimizationAnalyzer**: Suggests JOIN improvements
- **FindAllAnalyzer**: Detects missing LIMIT clauses that amplify index issues

---

**[← N+1 Queries](n-plus-one)** | **[Security Issues →](security)**
