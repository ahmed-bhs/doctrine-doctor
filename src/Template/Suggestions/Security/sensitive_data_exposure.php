<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $methodName
 * @var mixed $exposedFields
 * @var mixed $exposureType
 * @var mixed $context
 */
['entity_class' => $entityClass, 'method_name' => $methodName, 'exposed_fields' => $exposedFields, 'exposure_type' => $exposureType] = $context;
$e                                                                                                                                   = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                                                       = strrchr($entityClass, '\\');
$shortClass                                                                                                                          = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<div class="suggestion-header"><h4>Sensitive data exposure</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><strong>Security issue in <?php echo $e($shortClass); ?>::<?php echo $e($methodName); ?>()</strong><br>
Exposure type: <?php echo $e($exposureType); ?><br>
Exposed fields: <code><?php echo implode(', ', array_map($e, $exposedFields)); ?></code></div>

<p>Sensitive fields like passwords and API tokens are being serialized. This can expose them in API responses, logs, or error messages.</p>

<h4>Use #[Ignore]</h4>
<div class="query-item"><pre><code class="language-php">use Symfony\Component\Serializer\Annotation\Ignore;

class <?php echo $e($shortClass); ?> {
    #[Ignore]
    private string $password;

    #[Ignore]
    private ?string $apiToken = null;
}</code></pre></div>

<p>Use <code>#[Ignore]</code> or <code>#[Groups]</code> to exclude sensitive fields from serialization. Never expose passwords, tokens, or personally identifiable information.</p>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Sensitive data exposed in %s::%s()', $shortClass, $methodName)];
