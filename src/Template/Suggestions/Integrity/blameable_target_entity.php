<?php

declare(strict_types=1);

/**
 * @var string      $entityClass   Entity class name
 * @var string      $fieldName     Field name
 * @var string|null $current_target Current target entity
 */

// Extract context variables
$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';
$currentTarget = $context['current_target'] ?? 'unknown';

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-content">
    <h3>Blameable field points to wrong entity</h3>
    <p>
        <code><?= $fieldName ?></code> should reference a User entity, but currently points to <code><?= $e($currentTarget) ?></code>. Blameable fields must reference the user/account entity to properly track who created or modified the entity.
    </p>

    <h3>Fix</h3>
    <pre><code class="language-php">use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?= $fieldName ?>;
}</code></pre>

    <p>Make sure to use your actual User entity class. Common names include App\Entity\User, App\Entity\Account, or App\Security\User.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
