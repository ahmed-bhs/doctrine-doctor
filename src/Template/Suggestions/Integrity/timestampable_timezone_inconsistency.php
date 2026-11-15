<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var int $datetimeCount
 * @var mixed $datetimetzCount
 * @var array<string, mixed> $context
 */
$datetimeCount   = (int) ($context['datetime_count'] ?? 0);
$datetimetzCount = (int) ($context['datetimetz_count'] ?? 0);
// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Use one datetime type everywhere'); ?>

<div class="suggestion-content">
    <p><?php echo $datetimeCount; ?> fields use <code>datetime</code> and <?php echo $datetimetzCount; ?> use <code>datetimetz</code>. Comparing or sorting values across them mixes local times with offset-aware ones.</p>

    <div class="query-item">
        <pre><code class="language-php">// Usual choice: datetime everywhere, values written in UTC
#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
private \DateTimeImmutable $createdAt;</code></pre>
    </div>

    <p>Choose <code>datetimetz_immutable</code> everywhere instead when the original offset matters (calendars, bookings), then run <code>doctrine:migrations:diff</code>.</p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetime', 'Doctrine: DateTime Types'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use one datetime type: %d datetime vs %d datetimetz fields', $datetimeCount, $datetimetzCount),
];
