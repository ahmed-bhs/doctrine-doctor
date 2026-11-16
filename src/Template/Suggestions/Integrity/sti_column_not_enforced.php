<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$subclass = (string) ($context['subclass'] ?? 'Subclass');
$field = (string) ($context['field'] ?? 'field');
$column = (string) ($context['column'] ?? 'column');
$table = (string) ($context['table'] ?? 'table');
$discriminatorColumn = (string) ($context['discriminator_column'] ?? 'dtype');
$discriminatorValue = (string) ($context['discriminator_value'] ?? 'subtype');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Keep the rule in the database with a CHECK constraint'); ?>
<div class="suggestion-content">
<p>Doctrine creates <code><?php echo $e($table); ?>.<?php echo $e($column); ?></code> as nullable because the other subtypes leave it empty. A constraint on the discriminator restores the rule for <code><?php echo $e($subclass); ?></code> only.</p>

<div class="query-item"><pre><code class="language-php">$this->addSql("ALTER TABLE <?php echo $e($table); ?> ADD CONSTRAINT chk_<?php echo $e($table); ?>_<?php echo $e($column); ?>

    CHECK (<?php echo $e($discriminatorColumn); ?> &lt;&gt; '<?php echo $e($discriminatorValue); ?>' OR <?php echo $e($column); ?> IS NOT NULL)");</code></pre></div>

<p>If many fields of the subtypes must be required, Class Table Inheritance gives each subtype its own table, where the columns can be NOT NULL.</p>
</div>

<?php
$html = (string) ob_get_clean();

return [
    'code' => $html,
    'description' => sprintf('Add a CHECK constraint so that %s::$%s is required in the database', $subclass, $field),
];
