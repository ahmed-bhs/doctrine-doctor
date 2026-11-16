<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $methodName
 * @var string $insecureFunction
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'RandomGenerator');
$methodName = (string) ($context['method_name'] ?? 'generate');
$insecureFunction = (string) ($context['insecure_function'] ?? 'rand');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                           = strrchr($entityClass, '\\');
$shortClass                                                                                              = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<?php echo suggestionHeader('Use a cryptographically secure generator'); ?>
<div class="suggestion-content">
<div class="alert alert-danger"><?php echo $e($shortClass); ?>::<?php echo $e($methodName); ?>() calls <code><?php echo $e($insecureFunction); ?>()</code>, whose output can be predicted. Tokens, passwords and session IDs need <code>random_bytes()</code> or <code>random_int()</code>.</div>

<div class="query-item"><pre><code class="language-php">// Before
$token = bin2hex(<?php echo $e($insecureFunction); ?>(16));

// After
$token = bin2hex(random_bytes(16));
$pin = random_int(100000, 999999);</code></pre></div>

<?php echo suggestionDocLink('https://www.php.net/manual/en/function.random-bytes.php', 'PHP random_bytes() Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Replace %s() with random_bytes() in %s::%s()', $insecureFunction, $shortClass, $methodName)];
