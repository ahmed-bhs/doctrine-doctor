<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Comment\HeaderCommentFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

$projectName   = 'Doctrine Doctor';
$projectAuthor = 'Ahmed EBEN HASSINE';
$startYear     = 2025;
$currentYear   = (int) date('Y');
$copyrightYears = $startYear === $currentYear ? (string) $startYear : "$startYear-$currentYear";

$header = <<<EOF
This file is part of the $projectName.
(c) $copyrightYears $projectAuthor
For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

return ECSConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/var',
        __DIR__ . '/vendor',
        __DIR__ . '/src/Template/Suggestions', // Exclude template files with PHP interpolation (aligned with PHPStan, Deptrac, PHPMD)
    ])

    // Base sets
    ->withPreparedSets(
        arrays: true,
        comments: true,
        docblocks: true,
        spaces: true,
        namespaces: true,
        controlStructures: true,
        phpunit: true,
        cleanCode: true,
        strict: true,
        psr12: true,
    )

    // Additional rules (many are already in prepared sets)
    ->withRules([
        // Types stricts
        PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer::class,
        PhpCsFixer\Fixer\Strict\StrictComparisonFixer::class,
        PhpCsFixer\Fixer\FunctionNotation\VoidReturnFixer::class,

        // Optimisations
        PhpCsFixer\Fixer\Operator\TernaryToNullCoalescingFixer::class,
        PhpCsFixer\Fixer\CastNotation\ModernizeTypesCastingFixer::class,

        // Import
        PhpCsFixer\Fixer\Import\NoUnusedImportsFixer::class,
    ])

    // Configured rules
    ->withConfiguredRule(HeaderCommentFixer::class, [
        'header'   => $header,
        'location' => 'after_open',
        'separate' => 'both',
    ])

    ->withConfiguredRule(PhpCsFixer\Fixer\ControlStructure\YodaStyleFixer::class, [
        'equal'            => true,
        'identical'        => true,
        'less_and_greater' => false,
    ])

    ->withConfiguredRule(PhpCsFixer\Fixer\ControlStructure\TrailingCommaInMultilineFixer::class, [
        'elements' => ['arrays', 'arguments', 'parameters'],
    ])

    ->withConfiguredRule(PhpCsFixer\Fixer\PhpUnit\PhpUnitMethodCasingFixer::class, [
        'case' => 'snake_case',
    ])

    ->withConfiguredRule(PhpCsFixer\Fixer\PhpUnit\PhpUnitTestCaseStaticMethodCallsFixer::class, [
        'call_type' => 'self',
    ])

    ->withConfiguredRule(PhpCsFixer\Fixer\Import\OrderedImportsFixer::class, [
        'sort_algorithm' => 'alpha',
        'imports_order'  => ['const', 'class', 'function'],
    ])

    ->withConfiguredRule(PhpCsFixer\Fixer\Operator\ConcatSpaceFixer::class, [
        'spacing' => 'one',
    ])

    // Disabled rules
    ->withSkip([
        // IMPORTANT: SingleQuoteFixer transforms "\n" into literal line break, breaking the code!
        PhpCsFixer\Fixer\StringNotation\SingleQuoteFixer::class,

        // Formatting/alignment that we don't want
        PhpCsFixer\Fixer\Operator\BinaryOperatorSpacesFixer::class,
        PhpCsFixer\Fixer\Whitespace\MethodChainingIndentationFixer::class,
        PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer::class,
        PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer::class,

        // Classes
        PhpCsFixer\Fixer\ClassNotation\FinalClassFixer::class,
        PhpCsFixer\Fixer\ClassNotation\SelfAccessorFixer::class,

        // Tests
        PhpCsFixer\Fixer\PhpUnit\PhpUnitTestClassRequiresCoversFixer::class,

        // Arrays (Symplify)
        Symplify\CodingStandard\Fixer\ArrayNotation\ArrayListItemNewlineFixer::class,
        Symplify\CodingStandard\Fixer\ArrayNotation\StandaloneLineInMultilineArrayFixer::class,
        Symplify\CodingStandard\Fixer\ArrayNotation\ArrayOpenerAndCloserNewlineFixer::class,
    ]);
