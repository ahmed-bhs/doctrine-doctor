<?php

declare(strict_types=1);

['setting' => $setting, 'current_value' => $currentValue, 'recommended_value' => $recommendedValue, 'description' => $description, 'fix_command' => $fixCommand] = $context;
$e                                                                                                                                                               = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="suggestion-header"><h4>Configuration Issue: <?php echo $e($setting); ?></h4></div>
<div class="suggestion-content">
<div class="alert alert-warning">⚙️ <strong>Configuration needs adjustment</strong><?php if ($description) {
    ?><br><?php echo $e($description); ?><?php
} ?></div>
<h4>Current vs Recommended</h4>
<table><tr><th>Current</th><td><code><?php echo $e($currentValue); ?></code></td></tr><tr><th>Recommended</th><td><code><?php echo $e($recommendedValue); ?></code></td></tr></table>
<?php if ($fixCommand) { ?>
<h4>How to Fix</h4>
<div class="query-item"><pre><code class="language-bash"><?php echo $e($fixCommand); ?></code></pre></div>
<?php } ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Change %s from "%s" to "%s"', $setting, $currentValue, $recommendedValue)];
