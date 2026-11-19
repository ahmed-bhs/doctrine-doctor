<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $methodName
 * @var mixed $exposedFields
 * @var mixed $exposureType
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'User');
$methodName = (string) ($context['method_name'] ?? 'serialize');
$exposedFields = $context['exposed_fields'] ?? ['password'];
$exposureType = (string) ($context['exposure_type'] ?? 'serialization');
if (!is_array($exposedFields) || [] === $exposedFields) {
    $exposedFields = ['password'];
}
$exposedFields = array_values(array_map(static fn (mixed $field): string => (string) $field, $exposedFields));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                                                       = strrchr($entityClass, '\\');
$shortClass                                                                                                                          = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<?php echo suggestionHeader('Exclude sensitive fields from serialization'); ?>
<div class="suggestion-content">
<div class="alert alert-danger"><?php echo t('%s::%s() exposes <code>%s</code> (%s). These values can then reach API responses, logs or error pages.', $e($shortClass), $e($methodName), implode(', ', array_map(static fn (mixed $field): string => $e((string) $field), $exposedFields)), $e($exposureType)); ?></div>

<div class="query-item"><pre><code class="language-php">use Symfony\Component\Serializer\Attribute\Ignore;

class <?php echo $e($shortClass); ?>

{
    #[Ignore]
    private string $password;

    #[Ignore]
    private ?string $apiToken = null;
}</code></pre></div>

<p><?php echo t('Serialization groups (<code>#[Groups]</code>) work too: list only the public fields in the group you serialize.'); ?></p>

<?php echo suggestionDocLink('https://symfony.com/doc/current/serializer.html#selecting-specific-properties', 'Symfony Serializer Groups'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => t('Exclude sensitive fields from serialization in %s::%s()', $shortClass, $methodName)];
