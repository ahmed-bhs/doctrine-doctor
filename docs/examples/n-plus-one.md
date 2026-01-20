---
layout: default
title: N+1 Queries
parent: Examples
nav_order: 1
---

# N+1 Query Detection Examples

Learn how to identify and fix N+1 query problems with Doctrine Doctor.

---

## What is an N+1 Query?

An N+1 query problem occurs when:

1. You execute 1 query to fetch N records
2. Then execute N additional queries to fetch related data for each record

**Result**: N+1 queries instead of 1-2 optimized queries.

---

## Example 1: Basic N+1 in Templates

### Problem Code

```php
<?php
// src/Controller/ArticleController.php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ArticleController extends AbstractController
{
    #[Route('/articles', name: 'app_articles')]
    public function index(ArticleRepository $articleRepository): Response
    {
        $articles = $articleRepository->findAll();  // 1 query

        return $this->render('article/index.html.twig', [
            'articles' => $articles,
        ]);
    }
}
```

```twig
{% raw %}{# templates/article/index.html.twig #}
{% for article in articles %}
    <article>
        <h2>{{ article.title }}</h2>
        <p>By {{ article.author.name }}</p>  {# +N queries! #}
    </article>
{% endfor %}{% endraw %}
```

### Doctrine Doctor Detection

```text
🔴 Critical: N+1 Query Detected

Query executed 100 times:
SELECT * FROM author WHERE id = ?

Suggestion: Use eager loading with JOIN
```

### Solution

```php
<?php

public function index(ArticleRepository $articleRepository): Response
{
    $articles = $articleRepository
        ->createQueryBuilder('a')
        ->leftJoin('a.author', 'author')
        ->addSelect('author')  // Eager load authors
        ->getQuery()
        ->getResult();

    return $this->render('article/index.html.twig', [
        'articles' => $articles,
    ]);
}
```

**Result**: 1 query with a JOIN instead of 101 queries.

---

## Example 2: Nested N+1 Queries

### Problem Code

```php
<?php
// Multiple levels of lazy loading

public function index(ArticleRepository $articleRepository): Response
{
    $articles = $articleRepository->findAll();

    return $this->render('article/index.html.twig', [
        'articles' => $articles,
    ]);
}
```

```twig
{% raw %}{% for article in articles %}
    <article>
        <h2>{{ article.title }}</h2>
        <p>By {{ article.author.name }}</p>
        <p>From {{ article.author.country.name }}</p>  {# Nested N+1! #}

        <h3>Comments:</h3>
        {% for comment in article.comments %}  {# Another N+1! #}
            <p>{{ comment.content }} - {{ comment.author.name }}</p>  {# And another! #}
        {% endfor %}
    </article>
{% endfor %}{% endraw %}
```

### Doctrine Doctor Detection

```text
🔴 Critical: Nested N+1 Query Detected

Multiple N+1 patterns detected:
- article.author: 100 queries
- author.country: 100 queries
- article.comments: 100 queries
- comment.author: 500 queries

Total: 801 queries instead of 1-2
```

### Solution

```php
<?php

public function index(ArticleRepository $articleRepository): Response
{
    $articles = $articleRepository
        ->createQueryBuilder('a')
        ->leftJoin('a.author', 'author')
        ->leftJoin('author.country', 'country')
        ->leftJoin('a.comments', 'comments')
        ->leftJoin('comments.author', 'commentAuthor')
        ->addSelect('author', 'country', 'comments', 'commentAuthor')
        ->getQuery()
        ->getResult();

    return $this->render('article/index.html.twig', [
        'articles' => $articles,
    ]);
}
```

**Result**: 1 query with multiple JOINs.

---

## Example 3: N+1 in Loops

### Problem Code

```php
<?php

public function generateReport(ArticleRepository $articleRepository): Response
{
    $articles = $articleRepository->findAll();

    $report = [];
    foreach ($articles as $article) {
        $report[] = [
            'title' => $article->getTitle(),
            'author' => $article->getAuthor()->getName(),  // N+1!
            'comments_count' => $article->getComments()->count(),  // N+1!
        ];
    }

    return $this->json($report);
}
```

### Solution

```php
<?php

public function generateReport(ArticleRepository $articleRepository): Response
{
    $articles = $articleRepository
        ->createQueryBuilder('a')
        ->leftJoin('a.author', 'author')
        ->leftJoin('a.comments', 'comments')
        ->addSelect('author', 'comments')
        ->getQuery()
        ->getResult();

    $report = [];
    foreach ($articles as $article) {
        $report[] = [
            'title' => $article->getTitle(),
            'author' => $article->getAuthor()->getName(),
            'comments_count' => $article->getComments()->count(),
        ];
    }

    return $this->json($report);
}
```

---

## Example 4: Collection Methods Triggering N+1

### Problem Code

```php
<?php

public function index(ArticleRepository $articleRepository): Response
{
    $articles = $articleRepository->findAll();

    foreach ($articles as $article) {
        // Each of these triggers a query!
        if ($article->getComments()->isEmpty()) {  // Query!
            continue;
        }

        $firstComment = $article->getComments()->first();  // Already loaded
        $commentCount = $article->getComments()->count();  // No new query
    }
}
```

### Solution

Either eager load:

```php
<?php

$articles = $articleRepository
    ->createQueryBuilder('a')
    ->leftJoin('a.comments', 'comments')
    ->addSelect('comments')
    ->getQuery()
    ->getResult();
```

Or use DQL for conditional logic:

```php
<?php

$articles = $articleRepository
    ->createQueryBuilder('a')
    ->leftJoin('a.comments', 'c')
    ->having('COUNT(c.id) > 0')  // Filter in database
    ->groupBy('a.id')
    ->getQuery()
    ->getResult();
```

---

## Configuration Tips

### Adjust N+1 Sensitivity

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            threshold: 3  # Detect with 3+ duplicate queries
```

**Recommended values**:

- Development: `2` (strict)
- Team environment: `5` (balanced)
- Large codebase: `10` (focus on major issues)

---

## Performance Impact

### Before Fix

```text
Queries: 501
Execution time: 1,247ms
Memory: 45MB
```

### After Fix

```text
Queries: 1
Execution time: 23ms
Memory: 8MB

Improvement: 98% faster, 82% less memory
```

---

## Related Analyzers

- **NestedRelationshipN1Analyzer**: Detects multi-level N+1 patterns
- **LazyLoadingAnalyzer**: Identifies excessive lazy loading
- **SlowQueryAnalyzer**: Finds slow queries that might indicate N+1

---

**[← First Steps]({{ site.baseurl }}/getting-started/first-steps)** | **[Missing Indexes →](missing-indexes)**
