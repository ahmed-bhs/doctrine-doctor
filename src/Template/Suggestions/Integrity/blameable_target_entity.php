<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';
$currentTarget = $context['current_target'] ?? 'unknown';

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));
$blameOn = str_contains(strtolower((string) $fieldName), 'update') ? 'update' : 'create';
$shortTarget = basename(str_replace('\\', '/', (string) $currentTarget));

ob_start();
?>
<?php echo suggestionHeader('Point the blameable field to the user entity'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>"><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> is a blameable field but references <code><?php echo $e($currentTarget); ?></code>. Blameable stores the authenticated user, so the association must target your user class.</div>

<div class="query-item"><pre><code class="language-php">use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
class <?php echo $e($shortClass); ?>

{
    #[Gedmo\Blameable(on: '<?php echo $blameOn; ?>')]
    // Before: #[ORM\ManyToOne(targetEntity: <?php echo $e($shortTarget); ?>::class)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $<?php echo $e($fieldName); ?> = null;
}</code></pre></div>

<p>If this field is a business relation to <code><?php echo $e($shortTarget); ?></code>, keep it, remove Blameable from it and track the actor in separate audit fields mapped to your user class.</p>

<?php echo suggestionDocLink('https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/blameable.md', 'Doctrine Extensions Blameable'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Blameable field %s::%s should reference a User entity', $shortClass, $fieldName),
];
