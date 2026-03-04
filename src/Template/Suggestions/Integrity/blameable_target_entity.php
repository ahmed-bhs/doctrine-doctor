<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';
$currentTarget = $context['current_target'] ?? 'unknown';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<div class="suggestion-header"><h4>Blameable field points to wrong entity</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><code><?php echo $e($fieldName); ?></code> should reference a User entity, but currently points to <code><?php echo $e($currentTarget); ?></code>.</div>

<p>Blameable fields must reference the user/account entity to properly track who created or modified the entity.</p>

<h4>Fix</h4>
<div class="query-item"><pre><code class="language-php">use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?php echo $e($shortClass); ?>

{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?php echo $e($fieldName); ?>;
}</code></pre></div>

<p>Make sure to use your actual User entity class. Common names include App\Entity\User, App\Entity\Account, or App\Security\User.</p>

<p><a href="https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/blameable.md" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine Extensions Blameable</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Blameable field %s::%s should reference a User entity', $shortClass, $fieldName),
];
