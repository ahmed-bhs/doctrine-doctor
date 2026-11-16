<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<?php echo suggestionHeader('Store the deletion date with its timezone'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> uses <code>datetime</code>, which drops the timezone. Deletion dates written from servers or users in different timezones then cannot be compared.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
private ?\DateTimeImmutable $<?php echo $e($fieldName); ?> = null;</code></pre></div>

<p>If the whole application stores UTC, keeping <code>datetime</code> is fine.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types', 'Doctrine ORM Mapping Types'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use datetimetz_immutable for the soft-delete field %s::%s', $shortClass, $fieldName),
];
