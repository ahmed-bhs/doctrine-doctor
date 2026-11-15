<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$auto_increment_count = $context['auto_increment_count'] ?? 0;
$uuid_count = $context['uuid_count'] ?? 0;
$auto_increment_entities = $context['auto_increment_entities'] ?? [];
$uuid_entities = $context['uuid_entities'] ?? [];

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Use one primary key strategy'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>">
    <strong><?php echo $e((string) $auto_increment_count); ?> entities</strong> use auto-increment integers and
    <strong><?php echo $e((string) $uuid_count); ?> entities</strong> use UUIDs. Mixing them means foreign keys of different types and identifiers that look different across the API.
</div>

<p><strong>UUID:</strong> <?php if (!empty($uuid_entities)) { ?><?php foreach (array_slice($uuid_entities, 0, 3) as $entity) { ?><code><?php echo $e((string) $entity); ?></code> <?php } ?><?php if (count($uuid_entities) > 3) { ?>... +<?php echo count($uuid_entities) - 3; ?><?php } ?><?php } ?><br>
<strong>Auto-increment:</strong> <?php if (!empty($auto_increment_entities)) { ?><?php foreach (array_slice($auto_increment_entities, 0, 3) as $entity) { ?><code><?php echo $e((string) $entity); ?></code> <?php } ?><?php if (count($auto_increment_entities) > 3) { ?>... +<?php echo count($auto_increment_entities) - 3; ?><?php } ?><?php } ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#identifier-generation-strategies', 'Doctrine ORM Identifier Generation Strategies'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Standardize entities on one primary key strategy',
];
