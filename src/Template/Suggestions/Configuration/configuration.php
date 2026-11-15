<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $setting
 * @var mixed $currentValue
 * @var string $recommendedValue
 * @var mixed $description
 * @var string $fixCommand
 * @var array<string, mixed> $context
 */
$setting = (string) ($context['setting'] ?? 'unknown_setting');
$currentValue = (string) ($context['current_value'] ?? '');
$recommendedValue = (string) ($context['recommended_value'] ?? '');
$description = (string) ($context['description'] ?? '');
$fixCommand = (string) ($context['fix_command'] ?? '');
$e                                                                                                                                                               = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$eCode                                                                                                                                                           = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_NOQUOTES, 'UTF-8');
ob_start();
?>
<?php echo suggestionHeader('Change ' . $setting); ?>
<div class="suggestion-content">
<p><code><?php echo $e($setting); ?></code> is set to <code><?php echo $e($currentValue); ?></code>; the recommended value is <code><?php echo $e($recommendedValue); ?></code>.<?php if ($description) {
    ?> <?php echo $e($description); ?><?php
} ?></p>
<?php if ($fixCommand) { ?>
<div class="query-item"><pre><code class="language-bash"><?php echo $eCode($fixCommand); ?></code></pre></div>
<?php } ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/advanced-configuration.html', 'Doctrine ORM Advanced Configuration'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Change %s from "%s" to "%s"', $setting, $currentValue, $recommendedValue)];
