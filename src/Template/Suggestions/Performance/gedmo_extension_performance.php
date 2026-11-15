<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity_class = (string) ($context['entity_class'] ?? '');
$extension_type = (string) ($context['extension_type'] ?? '');
$impact = (string) ($context['impact'] ?? '');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<?php echo suggestionHeader('Limit the queries added by Gedmo'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($entity_class); ?></code> uses the Gedmo <?php echo $e($extension_type); ?> extension, which <?php echo $e($impact); ?>.
<?php if ('Loggable' === $extension_type) { ?>
Each change to a versioned field inserts a log entry, and the log table grows without bound.</p>

<div class="query-item"><pre><code class="language-php">#[Gedmo\Loggable]
class Product
{
    #[ORM\Column]
    #[Gedmo\Versioned]   // history needed
    private int $price;

    #[ORM\Column]        // not versioned: no log entry
    private int $viewCount;
}</code></pre></div>

<p>Add an archive or cleanup job for the log table.</p>
<?php } elseif ('Translatable' === $extension_type) { ?>
Without a query hint, each translated entity loads its translations in a separate query.</p>

<div class="query-item"><pre><code class="language-php">use Doctrine\ORM\Query;
use Gedmo\Translatable\Query\TreeWalker\TranslationWalker;
use Gedmo\Translatable\TranslatableListener;

$query-&gt;setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, TranslationWalker::class);
$query-&gt;setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);</code></pre></div>

<p>The walker joins the translations into the same query.</p>
<?php } else { ?>
Compare the queries of a request with and without it in the Web Profiler.</p>
<?php } ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'Entity uses Gedmo ' . $extension_type . ', which adds queries: limit it to what you need'];
