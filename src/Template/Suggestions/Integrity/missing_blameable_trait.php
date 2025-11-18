<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $timestampFields
 * @var mixed $context
 */
['entity_class' => $entityClass, 'timestamp_fields' => $timestampFields] = $context;

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Add Blameable Trait to <?php echo $e($entityClass); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        Entity <strong><?php echo $e($entityClass); ?></strong> has timestamp field(s)
        (<code><?php echo implode('</code>, <code>', array_map($e, $timestampFields)); ?></code>)
        but no blameable fields (createdBy/updatedBy).
    </div>

    <p>Adding blameable fields provides a complete audit trail by tracking <strong>who</strong> created or modified each record, not just <strong>when</strong>.</p>

    <h4>Step 1: Create BlameableTrait (if not exists)</h4>
    <div class="query-item">
        <pre><code class="language-php">// src/Entity/Trait/BlameableTrait.php
namespace App\Entity\Trait;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

trait BlameableTrait
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): self
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }
}</code></pre>
    </div>

    <h4>Step 2: Use the trait in <?php echo $e($entityClass); ?></h4>
    <div class="query-item">
        <pre><code class="language-php">use App\Entity\Trait\BlameableTrait;

class <?php echo $e($entityClass); ?>

{
    use BlameableTrait;

    // ... existing fields
}</code></pre>
    </div>

    <h4>Step 3: Automatic population with Doctrine Extensions (Optional)</h4>
    <div class="query-item">
        <pre><code class="language-bash">composer require stof/doctrine-extensions-bundle</code></pre>
        <pre><code class="language-yaml"># config/packages/stof_doctrine_extensions.yaml
stof_doctrine_extensions:
    default_locale: en_US
    orm:
        default:
            blameable: true</code></pre>
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

    <h4>Benefits</h4>
    <ul>
        <li><strong>Complete Audit Trail:</strong> Know who created/modified each record</li>
        <li><strong>Security:</strong> Track user actions for compliance and debugging</li>
        <li><strong>Standardization:</strong> Consistent audit pattern across entities</li>
        <li><strong>Performance:</strong> Query by creator/modifier without expensive joins</li>
    </ul>

    <p>
        <a href="https://symfony.com/bundles/StofDoctrineExtensionsBundle/current/index.html#blameable" target="_blank" class="doc-link">
            📖 Doctrine Extensions: Blameable →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Add blameable fields to %s for complete audit trail', $entityClass),
];
