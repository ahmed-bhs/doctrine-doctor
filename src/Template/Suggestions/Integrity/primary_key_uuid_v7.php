<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$entity_name = $context['entity_name'] ?? 'App\Entity\Example';
$short_name = $context['short_name'] ?? 'Example';

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Generate UUID v7 instead of v4'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>"><?php echo t('<code>%s</code> uses random UUID v4 identifiers. Random keys land anywhere in the primary key index, which splits pages on insert; UUID v7 starts with a timestamp, so new rows are appended in order.', $e($short_name)); ?></div>

<div class="query-item"><pre><code class="language-php">// Before
#[ORM\CustomIdGenerator(class: UuidV4Generator::class)]

// After
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Id]
#[ORM\Column(type: UuidType::NAME, unique: true)]
private Uuid $id;

public function __construct() { $this-&gt;id = Uuid::v7(); }</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#identifier-generation-strategies', 'Doctrine ORM Identifier Generation Strategies'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Generate UUID v7 identifiers instead of v4'),
];
