<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$case = $context['case'] ?? 'drops';
$class = $context['class'] ?? 'Version20260101000000';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php if ('drops' === $case) { ?>
<?php echo suggestionHeader('Keep the data before dropping it'); ?>
<div class="suggestion-content">
<p>Split the change: first deploy code that no longer reads the column, then drop it in a later migration. If the data still matters, copy it first.</p>

<div class="query-item"><pre><code class="language-php">public function up(Schema $schema): void
{
    $this->addSql('INSERT INTO wishlist_item (customer_id, variant_id, added_at)
        SELECT customer_id, product_variant_id, added_at FROM app_wishlist');
    $this->addSql('DROP TABLE app_wishlist');
}</code></pre></div>
<?php } elseif ('irreversible' === $case) { ?>
<?php echo suggestionHeader('Write the down() that undoes up()'); ?>
<div class="suggestion-content">
<p>Each statement of <code><?php echo $e($class); ?>::up()</code> needs its opposite in <code>down()</code>. When it cannot be undone, say it explicitly.</p>

<div class="query-item"><pre><code class="language-php">public function down(Schema $schema): void
{
    $this->throwIrreversibleMigrationException('The column data is not kept.');
}</code></pre></div>
<?php } else { ?>
<?php echo suggestionHeader('Write SQL, not entities, in migrations'); ?>
<div class="suggestion-content">
<p>A migration runs months later, against the entities of that day. Move data with SQL, which stays true to the schema of the migration.</p>

<div class="query-item"><pre><code class="language-php">$this->addSql("UPDATE sylius_order SET notes = 'migrated' WHERE notes IS NULL");</code></pre></div>
<?php } ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-migrations/en/current/reference/migration-classes.html', 'Doctrine Migrations: migration classes'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Make %s safe to run and to roll back', $class),
];
