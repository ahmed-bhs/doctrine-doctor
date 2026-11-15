<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$entity_name = $context['entity_name'] ?? 'App\Entity\Example';
$short_name = $context['short_name'] ?? 'Example';

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Consider UUID v7 for public identifiers'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>"><code><?php echo $e($short_name); ?></code> uses an auto-increment integer ID. Sequential IDs reveal row counts and are easy to enumerate when they appear in URLs or APIs.</div>

<div class="query-item"><pre><code class="language-php">use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Id]
#[ORM\Column(type: UuidType::NAME, unique: true)]
private Uuid $id;

public function __construct()
{
    $this-&gt;id = Uuid::v7();
}</code></pre></div>

<p>Keep the integer ID for internal entities that are never exposed; it is smaller and faster to join.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#identifier-generation-strategies', 'Doctrine ORM Identifier Generation Strategies'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Consider UUID v7 for identifiers exposed outside the application',
];
