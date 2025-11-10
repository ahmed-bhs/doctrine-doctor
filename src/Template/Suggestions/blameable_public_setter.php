<?php

declare(strict_types=1);

/**
 * @var string $entityClass Entity class name
 * @var string $fieldName   Field name
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
        Having a public setter on <code><?= $fieldName ?></code> breaks audit trail integrity:
    </p>
    <ul>
        <li>The creator/author should be set once and never changed</li>
        <li>Public setters allow bypassing audit controls</li>
        <li>This violates the principle of immutable audit fields</li>
        <li>It enables tampering with historical records</li>
    </ul>

    <h3>Solution: Remove Public Setter</h3>
    <pre><code class="language-php">
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?= $fieldName ?>;

    public function __construct(User $<?= $fieldName ?>)
    {
        // Set in constructor - immutable after creation
        $this-><?= $fieldName ?> = $<?= $fieldName ?>;
    }

    public function get<?= ucfirst($fieldName) ?>(): User
    {
        return $this-><?= $fieldName ?>;
    }

    //  REMOVE THIS:
    // public function set<?= ucfirst($fieldName) ?>(User $user): void
    // {
    //     $this-><?= $fieldName ?> = $user;
    // }
}
</code></pre>

    <h3>Using Doctrine Extensions</h3>
    <p>When using Gedmo or KnpLabs, the libraries handle this automatically:</p>

    <pre><code class="language-php">
// Gedmo: No setters exposed
use Gedmo\Mapping\Annotation as Gedmo;

#[Gedmo\Blameable(on: 'create')]
#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(nullable: false)]
private User $<?= $fieldName ?>;

// KnpLabs: Protected setters only
use Knp\DoctrineBehaviors\Model\Blameable\BlameableTrait;

class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    use BlameableTrait;  // Provides protected setters
}
</code></pre>

    <h3>If you MUST allow changing (rare cases)</h3>
    <p>Use a specific method with business logic validation:</p>

    <pre><code class="language-php">
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    public function transferOwnership(User $newOwner, User $admin): void
    {
        // Validate: only admins can transfer
        if (!$admin->hasRole('ROLE_ADMIN')) {
            throw new AccessDeniedException('Only admins can transfer ownership');
        }

        // Log the change for audit trail
        $this->auditLog->log(sprintf(
            'Ownership transferred from %s to %s by admin %s',
            $this-><?= $fieldName ?>->getEmail(),
            $newOwner->getEmail(),
            $admin->getEmail()
        ));

        $this-><?= $fieldName ?> = $newOwner;
    }
}
</code></pre>

    <p><strong>Benefits:</strong> Immutable audit fields • No tampering • Compliance-ready • Clear ownership</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
