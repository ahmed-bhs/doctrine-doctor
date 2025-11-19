<?php

declare(strict_types=1);

/**
 * Template for mixed primary key strategies suggestion.
 * @var int           $auto_increment_count Number of entities using auto-increment
 * @var int           $uuid_count           Number of entities using UUIDs
 * @var array<string> $auto_increment_entities List of auto-increment entities
 * @var array<string> $uuid_entities         List of UUID entities
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$auto_increment_count = $context['auto_increment_count'] ?? 0;
$uuid_count = $context['uuid_count'] ?? 0;
$auto_increment_entities = $context['auto_increment_entities'] ?? [];
$uuid_entities = $context['uuid_entities'] ?? [];

ob_start();
?>

<div class="mixed-primary-keys">
    <h2>Mixed Primary Key Strategies</h2>

    <div class="key-statistics">
        <p><strong>Your codebase uses:</strong></p>
        <ul>
            <li><strong><?= htmlspecialchars((string) $auto_increment_count) ?> entities</strong> with auto-increment (INT)</li>
            <li><strong><?= htmlspecialchars((string) $uuid_count) ?> entities</strong> with UUIDs</li>
        </ul>
    </div>

    <div class="information-note">
        <p><strong>This is informational</strong> - mixed strategies are valid but add complexity (FK type mismatches, inconsistent APIs).</p>
    </div>

    <div class="recommendation">
        <p><strong>Recommendation:</strong> Standardize on one strategy unless you have specific reasons (e.g., public vs internal entities).</p>
    </div>

    <div class="entities-lists">
        <p><strong>UUID entities:</strong> <?php if (!empty($uuid_entities)): ?><?php foreach (array_slice($uuid_entities, 0, 3) as $entity): ?><code><?= htmlspecialchars($entity) ?></code> <?php endforeach; ?><?php if (count($uuid_entities) > 3): ?>... +<?= count($uuid_entities) - 3 ?><?php endif; ?><?php endif; ?></p>
        <p><strong>Auto-increment:</strong> <?php if (!empty($auto_increment_entities)): ?><?php foreach (array_slice($auto_increment_entities, 0, 3) as $entity): ?><code><?= htmlspecialchars($entity) ?></code> <?php endforeach; ?><?php if (count($auto_increment_entities) > 3): ?>... +<?= count($auto_increment_entities) - 3 ?><?php endif; ?><?php endif; ?></p>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
