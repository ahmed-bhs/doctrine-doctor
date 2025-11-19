<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $description
 * @var mixed $code
 * @var mixed $filePath
 * @var mixed $context
 */
['description' => $description, 'code' => $code, 'file_path' => $filePath] = $context;
$e                                                                         = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="suggestion-header"><h4>Code Quality Suggestion</h4></div>
<div class="suggestion-content">
<p><?php echo nl2br($e($description)); ?></p>
<?php if ($filePath) { ?>
<p><strong>File:</strong> <code><?php echo $e($filePath); ?></code></p>
<?php } ?>
<h4>Suggested Code</h4>
<div class="query-item"><pre><code class="language-php"><?php echo $code; ?></code></pre></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => $description];
