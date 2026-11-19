<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $description
 * @var mixed $code
 * @var string $filePath
 * @var array<string, mixed> $context
 */
$description = tr((string) ($context['description'] ?? ''));
$code = (string) ($context['code'] ?? '');
$filePath = (string) ($context['file_path'] ?? '');
$title = (string) ($context['title'] ?? 'Suggested change');
// /app/src/Entity/Supplier.php:93 reads better as src/Entity/Supplier.php:93
if (false !== ($srcPosition = strpos($filePath, '/src/'))) {
    $filePath = substr($filePath, $srcPosition + 1);
}
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<?php echo suggestionHeader($title); ?>
<div class="suggestion-content">
<p><?php echo nl2br($e($description)); ?></p>
<?php if ($filePath) { ?>
<p><?php echo t('In <code>%s</code>', $e($filePath)); ?></p>
<?php } ?>
<div class="query-item"><pre><code class="language-php"><?php echo $e($code); ?></code></pre></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => '' !== $description ? $description : 'Apply the suggested code change'];
