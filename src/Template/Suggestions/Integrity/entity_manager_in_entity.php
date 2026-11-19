<?php

declare(strict_types=1);

/**
 * Template for EntityManager in Entity suggestion.
 * Context variables:
 * @var string       $bad_code - Example of incorrect code
 * @var string       $good_code - Example of correct code
 * @var string       $description - Description of the issue
 * @var array<mixed> $benefits - List of benefits of separation
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$badCode = $context['bad_code'] ?? null;
$goodCode = $context['good_code'] ?? null;
$description = $context['description'] ?? '';
$benefits = $context['benefits'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = 1 === preg_match('/\bclass\s+(\w+)/', (string) $badCode, $matches) ? $matches[1] : 'Entity';

ob_start();
?>
<?php echo suggestionHeader('Move persistence out of the entity'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s</code> calls the EntityManager itself, so it cannot be built or tested without a database and flushes at hidden points. Keep the domain logic in the entity and let a service persist it.', $e($shortClass)); ?></p>

<div class="query-item"><pre><code class="language-php">final class <?php echo $e($shortClass); ?>Service
{
    public function __construct(private EntityManagerInterface $em) {}

    public function addItem(<?php echo $e($shortClass); ?> $entity, Item $item): void
    {
        $entity->addItem($item); // domain logic only, no EntityManager
        $this->em->persist($item);
        $this->em->flush();
    }
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/architecture.html', 'Doctrine architecture'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Move EntityManager calls from the entity to a service'),
];
