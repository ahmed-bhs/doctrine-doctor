<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$rootClass = (string) ($context['root_class'] ?? 'RootEntity');
$columnName = (string) ($context['column_name'] ?? 'dtype');
$currentLength = (int) ($context['current_length'] ?? 0);
$longestValue = (string) ($context['longest_value'] ?? '');
$neededLength = (int) ($context['needed_length'] ?? 0);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$suggestedLength = max($neededLength, 32);

ob_start();
?>
<?php echo suggestionHeader('Widen the discriminator column'); ?>
<div class="suggestion-content">
<p>Column <code><?php echo $e($columnName); ?></code> holds <?php echo $currentLength; ?> characters, but the value <code><?php echo $e($longestValue); ?></code> needs <?php echo $neededLength; ?>. The database truncates it on INSERT, and Doctrine can no longer map the row back to its class.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: '<?php echo $e($columnName); ?>', type: 'string', length: <?php echo $suggestedLength; ?>)]
class <?php echo $e($rootClass); ?>

{
}</code></pre></div>

<p>Shorter discriminator keys also work, but renaming them means rewriting every existing row.</p>
</div>

<?php
$html = (string) ob_get_clean();

return [
    'code' => $html,
    'description' => sprintf(
        'Discriminator column %s needs at least %d characters to store "%s"',
        $columnName,
        $neededLength,
        $longestValue,
    ),
];
