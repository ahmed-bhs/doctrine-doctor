---
layout: default
title: Template Security
parent: Advanced
nav_order: 2
---

# PHP Template Security Guide

{: .no_toc }

This document describes the security features implemented in the PHP template rendering system to prevent XSS (Cross-Site Scripting) and other injection attacks.

## Table of Contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## 1. Auto-Escaping with SafeContext

### 1.1 Overview

The `SafeContext` class provides automatic HTML escaping for all template variables, similar to Twig's auto-escaping feature.

**Security Benefits:**

- Prevents XSS by default
- Immutable context prevents variable tampering
- Type-safe access to variables
- Clear separation between safe and unsafe content

### 1.2 Usage in Templates

**Basic Usage (Auto-Escaped):**

```php
// Template context:
// ['username' => '<script>alert("XSS")</script>']

// SAFE - Auto-escaped
echo $context->username;
// Output: &lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;

// SAFE - Array access also auto-escapes
echo $context['username'];
// Output: &lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;
```

**Raw Output (Intentional Unescaped):**

```php
// UNSAFE - Use only for pre-sanitized content
echo $context->raw('formatted_sql');

// Example: SQL syntax highlighting is already sanitized
echo $context->raw('highlighted_code');
```

**Conditional Checks:**

```php
// Check if variable exists
if ($context->has('suggestion')) {
    echo $context->suggestion;
}

// Get all available keys
$keys = $context->keys();
```

### 1.3 Array Handling

Arrays are recursively escaped:

```php
// Context: ['items' => ['<script>', 'safe', '<img>']]

foreach ($context->items as $item) {
    echo $item; // Each item is auto-escaped
}

// Output:
// &lt;script&gt;
// safe
// &lt;img&gt;
```

### 1.4 Type Preservation

Non-string types are preserved:

```php
// Context:
// [
//     'count' => 42,
//     'price' => 19.99,
//     'active' => true,
//     'data' => null,
// ]

echo $context->count;  // 42 (int)
echo $context->price;  // 19.99 (float)
echo $context->active; // true (bool)
echo $context->data;   // null
```

---

## 2. Context-Aware Escaping

For advanced use cases, the `escapeContext()` helper provides context-specific escaping.

### 2.1 Available Contexts

| Context | Use Case | Example |
|---------|----------|---------|
| `html` | HTML content (default) | `<div><?php echo escapeContext($text, 'html'); ?></div>` |
| `attr` | HTML attributes | `<div class="<?php echo escapeContext($class, 'attr'); ?>">` |
| `js` | JavaScript strings | `var name = <?php echo escapeContext($name, 'js'); ?>;` |
| `css` | CSS identifiers | `.<?php echo escapeContext($class, 'css'); ?> { }` |
| `url` | URL parameters | `?param=<?php echo escapeContext($value, 'url'); ?>` |

### 2.2 Examples

**HTML Context (Default):**

```php
<p><?php echo escapeContext($userInput, 'html'); ?></p>
```

**Attribute Context:**

```php
<div class="user-<?php echo escapeContext($userId, 'attr'); ?>">
    <a href="/profile/<?php echo escapeContext($username, 'url'); ?>">
        Profile
    </a>
</div>
```

**JavaScript Context:**

```php
<script>
    var config = {
        username: <?php echo escapeContext($username, 'js'); ?>,
        message: <?php echo escapeContext($message, 'js'); ?>
    };
</script>
```

**CSS Context:**

```php
<style>
    .severity-<?php echo escapeContext($severity, 'css'); ?> {
        color: red;
    }
</style>
```

---

## 3. Security Best Practices

### 3.1 Default Rule: Use SafeContext

**GOOD:**

```php
// Auto-escaped by default
<h3><?php echo $context->title; ?></h3>
<p><?php echo $context->description; ?></p>
```

**📢 BAD:**

```php
// Manual extraction bypasses auto-escaping
extract($context);
<h3><?php echo $title; ?></h3> <!-- NOT ESCAPED! -->
```

### 3.2 When to Use raw()

Only use `raw()` for:

1. **Pre-sanitized HTML** (e.g., SQL syntax highlighting from Doctrine)
2. **Trusted content** (e.g., generated code examples)
3. **Already-escaped content** (avoid double-escaping)

**GOOD - Pre-sanitized:**

```php
// formatSqlWithHighlight() already escapes and adds HTML
<div class="query-item">
    <?php echo $context->raw('formatted_sql'); ?>
</div>
```

**📢 BAD - User input:**

```php
// NEVER use raw() on user input
<div>
    <?php echo $context->raw('user_comment'); ?> <!-- XSS VULNERABILITY! -->
</div>
```

### 3.3 Backward Compatibility

For existing templates using `extract()`, variables are still available but NOT auto-escaped:

```php
// Old style (still works but NOT auto-escaped)
extract($context);
echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); // Manual escape required

// New style (auto-escaped)
echo $context->username; // Safe by default
```

**Migration Recommendation:** Update templates to use `$context->` for new code.

---

## 4. Migration Guide

### 4.1 Updating Existing Templates

**Before:**

```php
<?php
// Old template using extract()
extract($context);
$e = fn(string $str): string => htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
?>

<div class="alert">
    <strong><?php echo $e($title); ?></strong>
    <p><?php echo $e($message); ?></p>
</div>
```

**After:**

```php
<?php
// New template using SafeContext
// No need for manual escaping function
?>

<div class="alert">
    <strong><?php echo $context->title; ?></strong>
    <p><?php echo $context->message; ?></p>
</div>
```

### 4.2 Migration Checklist

- [ ] Replace `$e($variable)` with `$context->variable`
- [ ] Remove manual `htmlspecialchars()` calls
- [ ] Use `$context->raw()` for pre-sanitized content
- [ ] Add tests for XSS prevention
- [ ] Review all `raw()` usage with security team

### 4.3 Gradual Migration

Templates can use both styles during migration:

```php
// Safe: Both work together
echo $context->username;      // Auto-escaped
echo htmlspecialchars($oldVar, ENT_QUOTES, 'UTF-8'); // Manual escape
```

---

## 5. Common Pitfalls

### 5.1 Double-Escaping

**Problem:**

```php
// 📢 BAD - Double-escaped
echo htmlspecialchars($context->username, ENT_QUOTES, 'UTF-8');
// Output: &amp;lt;script&amp;gt; (double-encoded)
```

**Solution:**

```php
// GOOD - Use raw() to get unescaped value, then escape once
echo htmlspecialchars($context->raw('username'), ENT_QUOTES, 'UTF-8');

// BETTER - Just use auto-escaping
echo $context->username;
```

### 5.2 Forgetting to Escape in Attributes

**Problem:**

```php
// 📢 VULNERABLE - Attribute injection
<div class="user-<?php echo $context->raw('userId'); ?>">
```

**Solution:**

```php
// SAFE - Auto-escaped
<div class="user-<?php echo $context->userId; ?>">

// SAFE - Context-aware escaping
<div class="user-<?php echo escapeContext($context->raw('userId'), 'attr'); ?>">
```

### 5.3 JavaScript Context Errors

**Problem:**

```php
// 📢 WRONG - HTML escaping in JavaScript breaks syntax
<script>
    var name = "<?php echo $context->name; ?>";
    // Output: var name = "&lt;script&gt;"; (breaks JS)
</script>
```

**Solution:**

```php
// CORRECT - Use JS context escaping
<script>
    var name = <?php echo escapeContext($context->raw('name'), 'js'); ?>;
    // Output: var name = "\u003Cscript\u003E"; (safe and valid JS)
</script>
```

### 5.4 Trusting "Safe" User Input

**Problem:**

```php
// 📢 DANGEROUS ASSUMPTION
// "Admin users are trusted, so we can use raw()"
if ($user->isAdmin()) {
    echo $context->raw('comment'); // STILL VULNERABLE!
}
```

**Solution:**

```php
// PRINCIPLE: Never trust ANY user input
echo $context->comment; // Always escape, regardless of user role
```

---

## XSS Prevention Examples

### Example 1: Preventing Script Injection

**Attack:**

```text
Input: <script>alert('XSS')</script>
```

**Defense:**

```php
echo $context->input;
// Output: &lt;script&gt;alert(&#039;XSS&#039;)&lt;/script&gt;
// Browser displays: <script>alert('XSS')</script> (as text, not executed)
```

### Example 2: Preventing Attribute Injection

**Attack:**

```text
Input: " onclick="alert('XSS')
```

**Defense:**

```php
<button class="<?php echo $context->input; ?>">Click</button>
// Output: <button class="&quot; onclick=&quot;alert(&#039;XSS&#039;)">Click</button>
// Quote is escaped, attribute injection prevented
```

### Example 3: Preventing URL Injection

**Attack:**

```text
Input: javascript:alert('XSS')
```

**Defense:**

```php
<a href="<?php echo escapeContext($context->raw('url'), 'url'); ?>">Link</a>
// Output: <a href="javascript%3Aalert%28%27XSS%27%29">Link</a>
// URL is encoded, script execution prevented
```

---

## Testing Security

### Unit Tests

All security features are covered by unit tests in `tests/Unit/Template/Security/SafeContextTest.php`.

**Run security tests:**

```bash
vendor/bin/phpunit --testsuite unit --filter SafeContext
```

### Manual Testing

**Test XSS Prevention:**

```php
// Create test context with XSS payloads
$context = new SafeContext([
    'test1' => '<script>alert(1)</script>',
    'test2' => '<img src=x onerror=alert(1)>',
    'test3' => 'javascript:alert(1)',
]);

// Verify all are escaped
var_dump($context->test1); // Should NOT contain executable script
var_dump($context->test2); // Should NOT contain onerror handler
var_dump($context->test3); // Should NOT contain javascript: protocol
```

---

## References

- [OWASP XSS Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
- [PHP htmlspecialchars() Documentation](https://www.php.net/manual/en/function.htmlspecialchars.php)
- [Twig Auto-Escaping Strategy](https://twig.symfony.com/doc/3.x/api.html#escaper-extension)
- [Content Security Policy (CSP)](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP)

---

**[← Back to Main Documentation]({{ site.baseurl }}/)** | **[Template Analysis →](template-analysis)**
