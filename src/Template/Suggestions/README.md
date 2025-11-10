# Suggestion Templates

This directory contains all suggestion templates for DoctrineDoctor analyzers.

## Purpose

Templates separate presentation logic from analyzer business logic, making it easier to:

- Maintain consistent suggestion formatting
- Update suggestions without touching analyzer code
- Reuse common patterns across analyzers
- Test suggestion rendering independently

## 📋 Creating a New Template

### 1. Create the Template File

Create a new PHP file in this directory: `{template_name}.php`

```php
<?php

declare(strict_types=1);

/**
 * Template for {Your Analyzer Name}
 *
 * Context variables:
 * @var string $entity_class - Entity class name
 * @var string $field_name - Field name
 * @var int $count - Number of occurrences
 */

// Extract context for clarity and type safety
[
    'entity_class' => $entityClass,
    'field_name' => $fieldName,
    'count' => $count,
] = $context;

// Helper function for safe HTML escaping
$e = fn(string $str): string => htmlspecialchars($str, ENT_QUOTES, 'UTF-8');

// Start output buffering
ob_start();
?>

<div class="suggestion-header">
    <h4>Your Suggestion Title</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Issue Description</strong><br>
        Detected <?= $count ?> <?= $count > 1 ? 'issues' : 'issue' ?> in <code><?= $e($entityClass) ?></code>
    </div>

    <h4>Problem</h4>
    <div class="code-block">
        <pre><code class="language-php">// Bad code example
class <?= $e($entityClass) ?> {
    private $<?= $e($fieldName) ?>;
}</code></pre>
    </div>

    <h4> Solution</h4>
    <div class="code-block">
        <pre><code class="language-php">// Good code example
class <?= $e($entityClass) ?> {
    private string $<?= $e($fieldName) ?>;
}</code></pre>
    </div>

    <h4>Benefits</h4>
    <ul>
        <li>Benefit 1</li>
        <li>Benefit 2</li>
    </ul>

    <p>
        <a href="https://docs.example.com" target="_blank" class="doc-link">
            📖 Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => sprintf(
        'Short description with %s context',
        $entityClass
    )
];
```

### 2. Use the Template in Your Analyzer

```php
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

private function createSuggestion(): SuggestionInterface
{
    return $this->suggestionFactory->createFromTemplate(
        templateName: 'your_template_name',  // Without .php extension
        context: [
            'entity_class' => $entityClass,
            'field_name' => $fieldName,
            'count' => $count,
        ],
        metadata: new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::high(),
            title: 'Your Issue Title',
            tags: ['performance', 'doctrine']
        )
    );
}
```

## 📐 Template Structure

### Required Elements

1. **Context Documentation** - Document all expected variables in PHPDoc
2. **Context Extraction** - Extract variables from `$context` array for type safety
3. **HTML Escaping** - Always escape user-provided data with `$e()` helper
4. **Output Buffering** - Use `ob_start()` and `ob_get_clean()`
5. **Return Array** - Must return `['code' => $html, 'description' => $text]`

### Available CSS Classes

- **Alerts**: `alert alert-danger`, `alert alert-warning`, `alert alert-info`
- **Code blocks**: `code-block` wrapper with `<pre><code class="language-php">`
- **Links**: `doc-link` for documentation links

### Best Practices

 **DO**:

- Document all context variables in PHPDoc
- Extract context variables for better IDE support
- Always escape dynamic content with `$e()`
- Use semantic HTML (h4 for headings, ul for lists)
- Include concrete code examples
- Add links to relevant documentation
- Use emojis sparingly for visual cues ( )

**DON'T**:

- Mix business logic with presentation
- Output unescaped user data
- Use inline styles (use CSS classes)
- Create overly complex templates
- Duplicate content across templates

## 🔍 Testing Your Template

1. **Manually**: Run the analyzer and check the output
2. **Validation**: Template existence is validated automatically by `SuggestionFactory::createFromTemplate()`
3. **Error Handling**: Missing templates throw `RuntimeException`

## 📁 Existing Templates

| Template | Analyzer | Purpose |
|----------|----------|---------|
| `flush_in_loop.php` | FlushInLoopAnalyzer | Detect flush() in loops |
| `eager_loading.php` | EagerLoadingAnalyzer | N+1 query detection |
| `join_too_many.php` | JoinOptimizationAnalyzer | Too many JOINs |
| `join_left_on_not_null.php` | JoinOptimizationAnalyzer | LEFT JOIN optimization |
| `join_unused.php` | JoinOptimizationAnalyzer | Unused JOINs |
| `float_for_money.php` | FloatForMoneyAnalyzer | Float for monetary values |
| `foreign_key_primitive.php` | ForeignKeyMappingAnalyzer | FK as primitive type |
| `dto_hydration.php` | DTOHydrationAnalyzer | DTO hydration for aggregations |
| `cascade_persist_independent.php` | CascadePersistAnalyzer | Cascade on independent entities |
| `bidirectional_*.php` | BidirectionalConsistencyAnalyzer | Bidirectional associations |

## 🚫 Deprecated Methods

The following methods in `SuggestionFactory` are **deprecated**:

- `createStructured()` - Use `createFromTemplate()` instead
- `createComparison()` - Use `createFromTemplate()` instead
- `createWithOptions()` - Use `createFromTemplate()` instead
- `createWithDocs()` - Use `createFromTemplate()` instead

**Always create a template file** instead of building suggestions programmatically!

## 💡 Tips

1. **Reuse patterns**: Look at existing templates for inspiration
2. **Keep it simple**: Templates should focus on presentation only
3. **Be specific**: Include concrete examples, not abstract advice
4. **Test rendering**: Check HTML output in different contexts
5. **Document context**: Clear PHPDoc helps other developers

---

**Need help?** Check existing templates or ask in the team chat!
