<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $query
 * @var mixed $vulnerableParams
 * @var string $riskLevel
 * @var array<string, mixed> $context
 */
$query = (string) ($context['query'] ?? 'SELECT u FROM User u WHERE u.name = :username');
$vulnerableParams = $context['vulnerable_parameters'] ?? ['username'];
$riskLevel = (string) ($context['risk_level'] ?? 'high');
// The risk level has its own catalog entry, since "warning" reads as a severity elsewhere.
$riskLabel = tr('risk level: ' . $riskLevel);
$riskLabel = str_starts_with($riskLabel, 'risk level: ') ? $riskLevel : $riskLabel;
if (!is_array($vulnerableParams) || [] === $vulnerableParams) {
    $vulnerableParams = ['username'];
}
$vulnerableParams = array_values(array_map(static fn (mixed $param): string => (string) $param, $vulnerableParams));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<?php echo suggestionHeader('Bind DQL parameters'); ?>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <?php echo t("Risk: %s. Parameters built by concatenation: <code>%s</code>. A value such as <code>' OR '1'='1</code> then changes the query itself.", $e($riskLabel), implode(', ', array_map(static fn (mixed $param): string => $e(tr($param)), $vulnerableParams))); ?>
    </div>

    <div class="query-item">
        <pre><code class="language-php">// Before
$query = $em-&gt;createQuery(
    "SELECT u FROM User u WHERE u.name = '" . $username . "'"
);

// After: the value is sent separately from the DQL
$query = $em-&gt;createQuery('SELECT u FROM User u WHERE u.name = :username');
$query-&gt;setParameter('username', $username);
$result = $query-&gt;getResult();</code></pre>
    </div>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#dql-query-parameters', 'Doctrine DQL parameters'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => t('Bind DQL values with setParameter() instead of concatenating them')];
