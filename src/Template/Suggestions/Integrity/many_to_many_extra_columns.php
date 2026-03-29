<?php

declare(strict_types=1);

/**
 * Suggestion template for refactoring ManyToMany with extra columns.
 * Context variables: entity_class, field_name, join_table, extra_columns
 */

$entity_class = (string) ($context['entity_class'] ?? '');
$field_name = (string) ($context['field_name'] ?? '');
$join_table = (string) ($context['join_table'] ?? '');
$extra_columns = (array) ($context['extra_columns'] ?? []);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<p>Your <code>ManyToMany</code> join table has extra columns beyond the two foreign keys. This indicates the relationship should be modeled with an explicit join entity.</p>

<h4>Current State</h4>

<p>Join table <code><?php echo $e($join_table); ?></code> has extra columns:</p>
<ul>
<?php foreach ($extra_columns as $col): ?>
<li><code><?php echo $e($col); ?></code></li>
<?php endforeach; ?>
</ul>

<h4>BEFORE — ManyToMany (no extra data)</h4>

<div class="code-block">
<pre><code class="language-php"><?php echo $e($entity_class); ?> {
    #[ORM\ManyToMany(targetEntity: Course::class)]
    private Collection $<?php echo $e($field_name); ?>;
}</code></pre>
</div>

<h4>AFTER — Two OneToMany with explicit join entity</h4>

<div class="code-block">
<pre><code class="language-php">// Enrollment.php (explicit join entity)
#[ORM\Entity]
class Enrollment {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Student::class, inversedBy: 'enrollments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Student $student;

    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'enrollments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

<?php foreach ($extra_columns as $col): ?>
    #[ORM\Column(type: 'string', nullable: true)]  // Adjust type as needed
    private ?string $<?php echo $e($col); ?> = null;

<?php endforeach; ?>
}

// Student.php (owning side)
#[ORM\Entity]
class Student {
    #[ORM\OneToMany(targetEntity: Enrollment::class, mappedBy: 'student', cascade: ['remove'])]
    private Collection $enrollments;
}

// Course.php (inverse side)
#[ORM\Entity]
class Course {
    #[ORM\OneToMany(targetEntity: Enrollment::class, mappedBy: 'course', cascade: ['remove'])]
    private Collection $enrollments;
}</code></pre>
</div>

<h4>Why This Matters</h4>

<ul>
<li><strong>Type Safety</strong> — Extra columns become typed properties on the join entity</li>
<li><strong>Queries</strong> — Access extra data without extra queries: `$enrollment->getEnrollmentDate()`</li>
<li><strong>Constraints</strong> — Foreign keys properly cascade and enforce referential integrity</li>
<li><strong>Maintainability</strong> — The join entity is explicit and documented in code</li>
</ul>

<h4>Migration Strategy</h4>

<ol>
<li>Create the new join entity with all columns</li>
<li>Add OneToMany associations on both sides</li>
<li>Migrate data from the old join table</li>
<li>Remove the old ManyToMany association</li>
<li>Update all queries to go through the join entity</li>
<li>Drop the automatic join table</li>
</ol>

<?php

$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Refactor ManyToMany with extra columns to explicit join entity with two OneToMany relations',
];
