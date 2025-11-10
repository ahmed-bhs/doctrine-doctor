<?php

declare(strict_types=1);

['entity_class' => $entityClass, 'method_name' => $methodName, 'insecure_function' => $insecureFunction] = $context;
$e                                                                                                       = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                           = strrchr($entityClass, '\\');
$shortClass                                                                                              = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<div class="suggestion-header"><h4>🔒 Insecure Random Number Generation</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger">⛔ <strong>SECURITY RISK: <?php echo $e($insecureFunction); ?>() in <?php echo $e($shortClass); ?>::<?php echo $e($methodName); ?>()</strong></div>
<h4>Insecure</h4>
<div class="query-item"><pre><code class="language-php">// INSECURE: Predictable!
$token = bin2hex(<?php echo $e($insecureFunction); ?>(16));</code></pre></div>
<h4> Cryptographically Secure</h4>
<div class="query-item"><pre><code class="language-php">//  SECURE: Cryptographically strong
$token = bin2hex(random_bytes(16));
// Or for integers:
$number = random_int(1000, 9999);</code></pre></div>
<p><strong>Never use</strong> <code>rand()</code>, <code>mt_rand()</code>, or <code>uniqid()</code> for security tokens, passwords, or session IDs!</p>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Replace %s() with random_bytes() in %s::%s()', $insecureFunction, $shortClass, $methodName)];
