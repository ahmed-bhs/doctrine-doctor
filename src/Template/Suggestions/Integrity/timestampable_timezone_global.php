<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Template for Global Timezone Warning.
 * Context variables provided by PhpTemplateRenderer::extract($context):
 * @var int $totalFields Number of timestamp fields found
 */
$totalFields = (int) ($context['total_fields'] ?? 0);
// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Store timestamps in UTC'); ?>

<div class="suggestion-content">
    <p><?php echo t('%s timestamp fields use <code>datetime</code>, which stores no timezone. That is fine when the application writes UTC and converts for display; use <code>datetimetz</code> only when the original offset matters.', $totalFields); ?></p>

    <div class="query-item">
        <pre><code class="language-php">// Writing in UTC
$this-&gt;createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

// Only where the original offset must be kept
#[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
private \DateTimeImmutable $startsAt;</code></pre>
    </div>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetimetz', 'Doctrine: DateTimeTZ Type'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('%d timestamp fields store no timezone; write them in UTC', $totalFields),
];
