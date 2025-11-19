<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $joinCount
 * @var mixed $context
 */
['join_count' => $joinCount] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Suggested Fix: Reduce Over-Eager Loading</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Over-Eager Loading Detected</strong><br>
        Found <strong><?php echo $joinCount; ?> JOINs</strong> in a single query. This can cause severe performance issues:
        <ul>
            <li><strong>Exponential data duplication</strong> with collection JOINs</li>
            <li><strong>Massive memory consumption</strong></li>
            <li><strong>Slow hydration</strong> due to large result sets</li>
        </ul>
    </div>

    <h4>Problem: The Cartesian Product Issue</h4>
    <div class="alert alert-info">
        <strong>Mathematical Reality:</strong><br>
        With <?php echo $joinCount; ?> collection JOINs, if each entity has just 10 related items:
        <ul>
            <li>Parent entity data repeated: <strong>10^<?php echo $joinCount; ?> = <?php echo number_format(10 ** $joinCount); ?> times</strong></li>
            <li>With 100 parent entities: <strong><?php echo number_format(100 * (10 ** $joinCount)); ?> rows</strong> in result set!</li>
            <li>Memory usage can reach <strong>hundreds of megabytes</strong> for a "simple" query</li>
        </ul>
    </div>

    <div class="query-item">
        <pre><code class="language-php">// BAD: Multiple collection JOINs cause data explosion
$query = $em->createQuery('
    SELECT a, c, t, u, r
    FROM App\Entity\Article a
    LEFT JOIN a.comments c      -- 10 comments per article
    LEFT JOIN a.tags t          -- 5 tags per article
    LEFT JOIN a.author u        -- 1 author per article
    LEFT JOIN c.replies r       -- 3 replies per comment
');

// Result set size: 1 article × 10 comments × 5 tags × 1 author × 3 replies
// = 150 rows for ONE article!
// Each row contains duplicate article data = 150× memory waste</code></pre>
    </div>

    <h4>Solution 1: Separate Queries Recommended</h4>
    <div class="query-item">
        <pre><code class="language-php">// GOOD: Split into multiple targeted queries
// Query 1: Main entities (no duplication)
$articles = $em->createQuery('
    SELECT a
    FROM App\Entity\Article a
')->getResult();

// Query 2: Eager load only what you need
if (/* you need comments */) {
    $em->createQuery('
        SELECT a, c
        FROM App\Entity\Article a
        LEFT JOIN a.comments c
        WHERE a.id IN (:ids)
    ')->setParameter('ids', array_column($articles, 'id'))
      ->getResult();
}

// Result: No data duplication, minimal memory usage!</code></pre>
    </div>

    <h4>Solution 2: Batch Fetch for ManyToOne Relations</h4>
    <div class="query-item">
        <pre><code class="language-php">// For ManyToOne/OneToOne relations, use Batch Fetch
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Article
{
    // Instead of JOIN, use batch fetching
    #[ORM\ManyToOne]
    #[ORM\BatchFetch(size: 10)]  // Loads in batches automatically
    private ?User $author = null;
}

// Now just query articles:
$articles = $em->createQuery('SELECT a FROM App\Entity\Article a')->getResult();

// When you access $article->getAuthor(), Doctrine batches the queries
// 100 articles → ~10 queries instead of 100 (N+1) or 1 with duplication</code></pre>
    </div>

    <h4>Solution 3: Use EXTRA_LAZY for Collections</h4>
    <div class="query-item">
        <pre><code class="language-php">// For collections you only partially access, use EXTRA_LAZY
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Article
{
    #[ORM\OneToMany(mappedBy: 'article', fetch: 'EXTRA_LAZY')]
    private Collection $comments;

    // Now you can access count, contains, slice without loading all:
    public function getCommentCount(): int
    {
        return $this->comments->count(); // Just COUNT(*) query!
    }

    public function getLatestComments(int $limit): array
    {
        return $this->comments->slice(0, $limit); // SELECT with LIMIT!
    }
}</code></pre>
    </div>

    <h4>Solution 4: Use DTOs for Read-Only Data</h4>
    <div class="query-item">
        <pre><code class="language-php">// BEST for APIs: Use partial objects or DTOs
$results = $em->createQuery('
    SELECT NEW ArticleDTO(
        a.id,
        a.title,
        COUNT(c.id),
        COUNT(DISTINCT t.id),
        u.name
    )
    FROM App\Entity\Article a
    LEFT JOIN a.comments c
    LEFT JOIN a.tags t
    LEFT JOIN a.author u
    GROUP BY a.id
')->getResult();

// Result: Single query, aggregated data, NO duplication!
// Perfect for API responses and dashboards</code></pre>
    </div>

    <h4>Real-World Example: The Impact</h4>
    <div class="alert alert-warning">
        <strong>Before (<?php echo $joinCount; ?> JOINs):</strong>
        <ul>
            <li>Result set: ~<?php echo number_format(100 * (10 ** min($joinCount - 1, 3))); ?> rows for 100 articles</li>
            <li>Memory: ~<?php echo number_format((100 * (10 ** min($joinCount - 1, 3)) * 2) / 1024); ?> MB</li>
            <li>Hydration time: ~<?php echo number_format((100 * (10 ** min($joinCount - 1, 3)) * 0.5) / 1000, 1); ?>s</li>
        </ul>
        <strong>After (separate queries):</strong>
        <ul>
            <li>Result set: ~<?php echo number_format(100 + 1000 + 500); ?> rows total</li>
            <li>Memory: ~3-5 MB (60-95% reduction!)</li>
            <li>Hydration time: ~0.1-0.2s (90%+ faster!)</li>
        </ul>
    </div>

    <h4>⚖️ Trade-offs: Why Multiple Queries Can Be Better</h4>
    <div class="alert alert-info">
        <strong>Common Misconception:</strong> "Fewer queries = better performance"<br>
        <strong>Reality:</strong> With JOINs on collections, ONE query can be worse than MULTIPLE queries!

        <strong>Why?</strong>
        <ul>
            <li><strong>No data duplication</strong>: Each entity fetched once</li>
            <li><strong>Less memory</strong>: Smaller result sets</li>
            <li><strong>Faster hydration</strong>: Fewer rows to process</li>
            <li><strong>Network efficiency</strong>: Less data transferred</li>
            <li><strong>Better caching</strong>: Individual queries cache better</li>
        </ul>
    </div>

    <h4>Decision Matrix</h4>
    <table style="width: 100%; border-collapse: collapse;">
        <tr style="background: #f5f5f5;">
            <th style="padding: 8px; border: 1px solid #ddd;">Scenario</th>
            <th style="padding: 8px; border: 1px solid #ddd;">Recommended Approach</th>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">ManyToOne (author, category)</td>
            <td style="padding: 8px; border: 1px solid #ddd;">Batch Fetch or single JOIN</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">OneToMany small (1-5 items)</td>
            <td style="padding: 8px; border: 1px solid #ddd;">JOIN FETCH (acceptable)</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">OneToMany large (10+ items)</td>
            <td style="padding: 8px; border: 1px solid #ddd;">Separate query or EXTRA_LAZY</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">Multiple collections</td>
            <td style="padding: 8px; border: 1px solid #ddd;">NEVER JOIN all together!</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">Read-only/API</td>
            <td style="padding: 8px; border: 1px solid #ddd;">DTOs with GROUP BY</td>
        </tr>
    </table>

    <h4>Best Practices</h4>
    <ul>
        <li><strong>Rule of thumb:</strong> Never JOIN more than 1-2 collections in a single query</li>
        <li><strong>ManyToOne only:</strong> Safe to JOIN multiple ManyToOne relations</li>
        <li><strong>Monitor memory:</strong> Watch Doctrine profiler for large result sets</li>
        <li><strong>Profile first:</strong> Measure before assuming fewer queries = faster</li>
        <li><strong>Use CTEs/subqueries:</strong> For complex reports, consider database views</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li><strong>Memory usage:</strong> 60-95% reduction</li>
            <li><strong>Hydration time:</strong> 70-90% faster</li>
            <li><strong>Network traffic:</strong> 80-95% less data transferred</li>
            <li><strong>Query execution:</strong> Simpler queries = better DB optimization</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            📖 Doctrine DQL JOIN Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Reduce over-eager loading (%d JOINs) to prevent data duplication and memory waste',
        $joinCount,
    ),
];
