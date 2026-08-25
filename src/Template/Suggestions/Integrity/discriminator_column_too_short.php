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

<div class="suggestion-header">
    <h4>Discriminator Column Too Short: <?php echo $e($rootClass); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        Column <code><?php echo $e($columnName); ?></code> holds <strong><?php echo $currentLength; ?></strong> characters,
        but the value <code><?php echo $e($longestValue); ?></code> needs <strong><?php echo $neededLength; ?></strong>.
        The database truncates it on INSERT, and Doctrine can no longer resolve the row back to its class.
    </div>

    <h4>Fix: widen the column</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: '<?php echo $e($columnName); ?>', type: 'string', length: <?php echo $suggestedLength; ?>)]
class <?php echo $e($rootClass); ?>

{
}</code></pre>
    </div>

    <p>Widening the column requires a migration. Shorter discriminator keys are an alternative,
    but renaming them rewrites every existing row.</p>
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
