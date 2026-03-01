<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$entity_name = $context['entity_name'] ?? 'App\Entity\Example';
$short_name = $context['short_name'] ?? 'Example';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Upgrade to UUID v7 for better performance</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><strong>Entity:</strong> <code><?php echo $e($short_name); ?></code></div>

<p>UUID v4 (random) causes slow inserts and fragmented indexes. UUID v7 (sequential, timestamp-based) offers <strong>58% faster inserts, 29% smaller indexes</strong>.</p>

<h4>Before: UUID v4</h4>
<div class="query-item"><pre><code class="language-php">use Symfony\Bridge\Doctrine\IdGenerator\UuidV4Generator;

#[ORM\Id]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator(class: UuidV4Generator::class)]
private UuidInterface $id;</code></pre></div>

<h4>After: UUID v7</h4>
<div class="query-item"><pre><code class="language-php">use Symfony\Component\Uid\UuidV7;

#[ORM\Id]
#[ORM\Column(type: 'uuid')]
private UuidV7 $id;

public function __construct() {
    $this->id = new UuidV7();
}</code></pre></div>

<p>Sequential ordering reduces B-tree page splits by 98%, improving insert speed and index efficiency.</p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Upgrade to UUID v7 for better performance',
];
