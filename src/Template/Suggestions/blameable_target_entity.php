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

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-content">
    <h3>Why is this a problem?</h3>
    <p>
        The blameable field <code><?= $fieldName ?></code> should reference a User entity, but currently points to:
        <code><?= $current_target ?? 'unknown' ?></code>
    </p>
    <ul>
        <li>Blameable fields must reference the user/account entity</li>
        <li>Using wrong entity type breaks audit trail logic</li>
        <li>This makes it impossible to track who created/modified the entity</li>
    </ul>

    <h3>Solution: Point to User Entity</h3>
    <pre><code class="language-php">
use App\Entity\User;  // Or your User entity namespace
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    // CORRECT: Points to User entity
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?= $fieldName ?>;
}
</code></pre>

    <h3>Common User Entity Names</h3>
    <p>Make sure to use your actual User entity class:</p>

    <pre><code class="language-php">
// Common patterns:
use App\Entity\User;              // Symfony default
use App\Entity\Account;           // Alternative naming
use App\Entity\Admin;             // Admin-only systems
use App\Security\User;            // Security namespace
use Acme\UserBundle\Entity\User;  // Bundle structure
</code></pre>

    <h3>Using Doctrine Extensions</h3>

    <pre><code class="language-php">
// Gedmo: Configure user entity
use Gedmo\Mapping\Annotation as Gedmo;

#[Gedmo\Blameable(on: 'create')]
#[ORM\ManyToOne(targetEntity: User::class)]  // ← Your User class
private User $<?= $fieldName ?>;

// KnpLabs: Configure in services.yaml
// config/services.yaml
knp_doctrine_behaviors:
    blameable:
        user_entity: App\Entity\User  // ← Configure your User class
</code></pre>

    <h3>Multiple User Types?</h3>
    <p>If you have multiple user types (Customer, Admin, etc.), use a common interface:</p>

    <pre><code class="language-php">
// 1. Create a common interface
interface BlameableUserInterface
{
    public function getId(): ?int;
    public function getUsername(): string;
}

// 2. Make all user types implement it
class User implements BlameableUserInterface { /* ... */ }
class Admin implements BlameableUserInterface { /* ... */ }

// 3. Use the interface in blameable
#[ORM\ManyToOne(targetEntity: BlameableUserInterface::class)]
private BlameableUserInterface $<?= $fieldName ?>;
</code></pre>

    <p><strong>Benefits:</strong> Correct relationships • Type safety • Clear audit trail • Better IDE support</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
