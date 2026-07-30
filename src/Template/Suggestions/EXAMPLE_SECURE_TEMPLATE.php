<?php

declare(strict_types=1);

/**
 * EXAMPLE: Secure Template with SafeContext Auto-Escaping.
 * This template demonstrates the security features of the PHP template system.
 * Context variables:
 * @var string $title       - Issue title
 * @var string $description - Issue description
 * @var string $query       - SQL query (may contain user input)
 * @var string $suggestion  - Pre-formatted HTML suggestion
 */

// ============================================================================
// SECURITY BEST PRACTICES DEMONSTRATED IN THIS TEMPLATE
// ============================================================================

// METHOD 1: Auto-escaped access (RECOMMENDED)
// Variables accessed via $context-> are automatically HTML-escaped
?>

<div class="issue-card">
    <h3><?php echo $context->title; ?></h3>
    <!-- Auto-escaped: Safe from XSS even if $title contains <script> -->

    <p><?php echo $context->description; ?></p>
    <!-- Auto-escaped: Safe from HTML injection -->

    <div class="query-display">
        <code><?php echo $context->query; ?></code>
        <!-- Auto-escaped: Safe even with malicious SQL -->
    </div>
</div>

<?php
// ============================================================================
// METHOD 2: Raw access for pre-sanitized content
// Use raw() ONLY when content is already sanitized (e.g., SQL highlighting)
// ============================================================================
?>

<div class="suggestion-container">
    <?php echo $context->raw('suggestion'); ?>
    <!-- Pre-formatted HTML from trusted source (formatSqlWithHighlight) -->
</div>

<?php
// ============================================================================
// METHOD 3: Context-aware escaping for specific contexts
// ============================================================================
?>

<div class="user-info">
    <!-- HTML context (default) -->
    <span><?php echo $context->username; ?></span>

    <!-- Attribute context -->
    <div class="user-<?php echo escapeContext($context->raw('userId'), 'attr'); ?>">

    <!-- URL context -->
    <a href="/profile/<?php echo escapeContext($context->raw('username'), 'url'); ?>">
        Profile
    </a>

    <!-- JavaScript context -->
    <script>
        var userData = {
            id: <?php echo escapeContext($context->raw('userId'), 'js'); ?>,
            name: <?php echo escapeContext($context->raw('username'), 'js'); ?>
        };
    </script>
</div>

<?php
// ============================================================================
// METHOD 4: Array access (also auto-escaped)
// ============================================================================
?>

<ul>
    <?php foreach ($context->items as $item): ?>
        <li><?php echo $item; ?></li>
        <!-- Each item is auto-escaped -->
    <?php endforeach; ?>
</ul>

<?php
// ============================================================================
//  ANTI-PATTERNS TO AVOID
// ============================================================================

//  DON'T: Use raw() on user input
// echo $context->raw('user_comment'); // VULNERABILITY!

//  DON'T: Extract and use directly without escaping
// extract($context);
// echo $title; // NOT auto-escaped!

//  DON'T: Double-escape
// echo htmlspecialchars($context->title); // Already escaped = double-encoded

//  DON'T: Use wrong context for escaping
// <script>var name = "<?php echo $context->name;?>";</script> // Breaks JS syntax
// CORRECT: var name = <?php echo escapeContext($context->raw('name'), 'js'); ?>;

// ============================================================================
// BACKWARD COMPATIBILITY
// ============================================================================

// Old templates using extract() still work (for gradual migration):
extract(['old_var' => 'value']);
echo htmlspecialchars($old_var ?? '', ENT_QUOTES, 'UTF-8'); // Manual escape still works

// But new code should prefer:
echo $context->old_var; // Auto-escaped

// ============================================================================
// Return template result
// ============================================================================

$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Example secure template demonstrating SafeContext features',
];
