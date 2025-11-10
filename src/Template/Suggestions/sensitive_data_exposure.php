<?php

declare(strict_types=1);

['entity_class' => $entityClass, 'method_name' => $methodName, 'exposed_fields' => $exposedFields, 'exposure_type' => $exposureType] = $context;
$e                                                                                                                                   = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                                                       = strrchr($entityClass, '\\');
$shortClass                                                                                                                          = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<div class="suggestion-header"><h4>🔒 Sensitive Data Exposure</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger">⛔ <strong>SECURITY: Sensitive data exposed in <?php echo $e($shortClass); ?>::<?php echo $e($methodName); ?>()</strong><br>
Exposure type: <?php echo $e($exposureType); ?><br>
Exposed fields: <code><?php echo implode(', ', array_map($e, $exposedFields)); ?></code></div>
<h4>Problem</h4>
<p>Sensitive fields (passwords, tokens, etc.) are being serialized/exposed.</p>
<h4> Solution: Use #[Ignore] or Custom Serializer</h4>
<div class="query-item"><pre><code class="language-php">use Symfony\Component\Serializer\Annotation\Ignore;

class <?php echo $e($shortClass); ?> {
    #[Ignore]  // Never serialize this field
    private string $password;

    #[Ignore]
    private ?string $apiToken = null;
}</code></pre></div>
<ul>
<li>Never serialize passwords, API tokens, or PII</li>
<li>Use <code>#[Ignore]</code> or <code>#[Groups]</code> for sensitive fields</li>
<li>Implement custom normalization for API responses</li>
</ul>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Prevent exposure of sensitive fields in %s::%s()', $shortClass, $methodName)];
