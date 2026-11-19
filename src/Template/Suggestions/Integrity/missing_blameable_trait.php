<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $timestampFields
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$timestampFields = $context['timestamp_fields'] ?? ['createdAt'];
if (!is_array($timestampFields) || [] === $timestampFields) {
    $timestampFields = ['createdAt'];
}
$timestampFields = array_values(array_map(static fn (mixed $field): string => (string) $field, $timestampFields));

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Record who creates and updates rows'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>%s</code> records when it changes (<code>%s</code>) but not who changed it. Add <code>createdBy</code> and <code>updatedBy</code> to complete the audit trail.', $e($entityClass), implode('</code>, <code>', array_map(static fn (mixed $field): string => $e((string) $field), $timestampFields))); ?></p>

    <div class="query-item">
        <pre><code class="language-php">use Gedmo\Mapping\Annotation as Gedmo;

class <?php echo $e($entityClass); ?>

{
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $createdBy = null;

    #[Gedmo\Blameable(on: 'update')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $updatedBy = null;
}</code></pre>
    </div>

    <p><?php echo t('The attributes need <code>stof/doctrine-extensions-bundle</code> with <code>blameable: true</code>; without it, set both fields yourself.'); ?></p>

    <?php echo suggestionDocLink('https://symfony.com/bundles/StofDoctrineExtensionsBundle/current/index.html#blameable', 'Doctrine Extensions: Blameable'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Add createdBy and updatedBy fields to %s', $entityClass),
];
