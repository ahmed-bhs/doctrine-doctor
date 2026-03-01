<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$auto_increment_count = $context['auto_increment_count'] ?? 0;
$uuid_count = $context['uuid_count'] ?? 0;
$auto_increment_entities = $context['auto_increment_entities'] ?? [];
$uuid_entities = $context['uuid_entities'] ?? [];

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Mixed primary key strategies</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning">
    <strong><?php echo $e((string) $auto_increment_count); ?> entities</strong> with auto-increment (INT),
    <strong><?php echo $e((string) $uuid_count); ?> entities</strong> with UUIDs
</div>

<p>Mixed strategies are valid but add complexity (FK type mismatches, inconsistent APIs). Standardize on one strategy unless you have specific reasons.</p>

<p><strong>UUID entities:</strong> <?php if (!empty($uuid_entities)): ?><?php foreach (array_slice($uuid_entities, 0, 3) as $entity): ?><code><?php echo $e((string) $entity); ?></code> <?php endforeach; ?><?php if (count($uuid_entities) > 3): ?>... +<?php echo count($uuid_entities) - 3; ?><?php endif; ?><?php endif; ?></p>
<p><strong>Auto-increment:</strong> <?php if (!empty($auto_increment_entities)): ?><?php foreach (array_slice($auto_increment_entities, 0, 3) as $entity): ?><code><?php echo $e((string) $entity); ?></code> <?php endforeach; ?><?php if (count($auto_increment_entities) > 3): ?>... +<?php echo count($auto_increment_entities) - 3; ?><?php endif; ?><?php endif; ?></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Mixed primary key strategies detected - consider standardizing',
];
