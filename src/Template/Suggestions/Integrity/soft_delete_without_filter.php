<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$entity = $context['entity'] ?? 'Supplier';
$column = $context['column'] ?? 'deleted_at';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Hide deleted rows with a Doctrine filter'); ?>
<div class="suggestion-content">
<p><?php echo t('A SQL filter adds the condition to every query on <code>%s</code>, relations included. Disable it explicitly where deleted rows must be shown, such as a trash page.', $e($entity)); ?></p>

<div class="query-item"><pre><code class="language-php">final class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $metadata, string $alias): string
    {
        return $metadata->hasField('deletedAt') ? "$alias.<?php echo $e($column); ?> IS NULL" : '';
    }
}

# config/packages/doctrine.yaml
doctrine: { orm: { filters: { soft_delete: { class: App\Doctrine\SoftDeleteFilter, enabled: true } } } }</code></pre></div>

<p><?php echo t('Gedmo\'s <code>SoftDeleteable</code> extension ships such a filter.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/filters.html', 'Doctrine: filters'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Add a filter that hides deleted %s rows', $entity),
];
