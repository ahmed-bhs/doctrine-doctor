<?php

declare(strict_types=1);

/**
 * @var string $entityClass Entity class name
 * @var string $fieldName   Field name
 */

// Extract context variables
$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-content">
    <h3>Why Store Timezone for Soft Delete?</h3>
    <p>
        The field <code><?= $fieldName ?></code> uses <code>datetime</code> type without timezone information:
    </p>
    <ul>
        <li>Deletion time may be misinterpreted in multi-timezone applications</li>
        <li>Important for audit trails and compliance (when was it REALLY deleted?)</li>
        <li>Timezone-aware timestamps are more precise and reliable</li>
        <li>Helps with data recovery and forensics</li>
    </ul>

    <h3>Solution: Use datetimetz_immutable</h3>
    <pre><code class="language-php">
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    // GOOD: datetimetz_immutable stores timezone
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\\DateTimeImmutable $<?= $fieldName ?> = null;

    public function delete(): void
    {
        // Timezone is preserved
        $this-><?= $fieldName ?> = new \\DateTimeImmutable();
    }
}
</code></pre>

    <h3>Comparison</h3>
    <table>
        <tr>
            <th>Type</th>
            <th>Stores Timezone?</th>
            <th>Best For</th>
        </tr>
        <tr>
            <td><code>datetime</code></td>
            <td>📢 No</td>
            <td>Single timezone apps</td>
        </tr>
        <tr>
            <td><code>datetimetz</code></td>
            <td>Yes</td>
            <td>Multi-timezone apps</td>
        </tr>
        <tr>
            <td><code>datetime_immutable</code></td>
            <td>📢 No</td>
            <td>Immutable, single TZ</td>
        </tr>
        <tr>
            <td><code>datetimetz_immutable</code></td>
            <td>Yes</td>
            <td>Best choice!</td>
        </tr>
    </table>

    <p><strong>Benefits:</strong> Precise timestamps • Multi-timezone support • Better audit trail • Forensics-ready</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
