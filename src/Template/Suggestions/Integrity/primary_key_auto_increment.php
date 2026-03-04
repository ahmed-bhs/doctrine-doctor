<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$entity_name = $context['entity_name'] ?? 'App\Entity\Example';
$short_name = $context['short_name'] ?? 'Example';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Consider UUID v7 instead of auto-increment</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><strong>Entity:</strong> <code><?php echo $e($short_name); ?></code></div>

<p>Auto-increment INT is simple but exposes business metrics, enables enumeration attacks, and doesn't work for distributed systems.</p>

<h4>Current</h4>
<div class="query-item"><pre><code class="language-php">#[ORM\Id]
#[ORM\GeneratedValue]
#[ORM\Column(type: 'integer')]
private int $id;</code></pre></div>

<h4>Alternative: UUID v7</h4>
<div class="query-item"><pre><code class="language-php">use Symfony\Component\Uid\UuidV7;

#[ORM\Id]
#[ORM\Column(type: 'uuid')]
private UuidV7 $id;

public function __construct() {
    $this->id = new UuidV7();
}</code></pre></div>

<p>Use UUID v7 for: API resources, distributed systems, security-sensitive entities.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#identifier-generation-strategies" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Identifier Generation Strategies</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Consider UUID v7 for better security and scalability',
];
