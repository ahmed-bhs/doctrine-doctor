<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $methodName
 * @var mixed $insecureFunction
 * @var mixed $context
 */
['entity_class' => $entityClass, 'method_name' => $methodName, 'insecure_function' => $insecureFunction] = $context;
$e                                                                                                       = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                           = strrchr($entityClass, '\\');
$shortClass                                                                                              = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<div class="suggestion-header"><h4>Insecure random generation</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><strong>Security risk</strong> - <?php echo $e($insecureFunction); ?>() in <?php echo $e($shortClass); ?>::<?php echo $e($methodName); ?>()</div>

<p>Functions like <code>rand()</code>, <code>mt_rand()</code>, and <code>uniqid()</code> are predictable and shouldn't be used for security-sensitive values like tokens or session IDs.</p>

<h4>Current code</h4>
<div class="query-item"><pre><code class="language-php">// Predictable
$token = bin2hex(<?php echo $e($insecureFunction); ?>(16));</code></pre></div>

<h4>Use cryptographically secure functions</h4>
<div class="query-item"><pre><code class="language-php">// Cryptographically secure
$token = bin2hex(random_bytes(16));
// Or for integers:
$number = random_int(1000, 9999);</code></pre></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Replace %s() with random_bytes() in %s::%s()', $insecureFunction, $shortClass, $methodName)];
