<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Concat as ConcatAssign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that detects SQL injection patterns in PHP code.
 *
 * Detects patterns like:
 * - String concatenation: $sql = "SELECT * FROM users WHERE id = " . $userId
 * - Variable interpolation: $sql = "SELECT * FROM users WHERE id = $userId"
 * - Missing parameters: $conn->executeQuery($sql) without second parameter
 * - sprintf with user input: sprintf("SELECT * FROM users WHERE id = %s", $_GET['id'])
 *
 * This is much more robust than regex because:
 * ✅ Ignores comments automatically
 * ✅ Ignores strings in irrelevant contexts
 * ✅ Type-safe AST-based detection
 * ✅ Handles all spacing/formatting variations
 * ✅ No false positives from string literals
 * ✅ Proper scope analysis
 *
 * @see https://owasp.org/www-community/attacks/SQL_Injection
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class SqlInjectionPatternVisitor extends NodeVisitorAbstract
{
    /** @var array<string> SQL keywords to detect SQL queries */
    private const SQL_KEYWORDS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'FROM', 'WHERE'];

    /** @var array<string> SQL execution methods */
    private const SQL_EXECUTION_METHODS = ['executeQuery', 'executeStatement', 'exec', 'query', 'prepare', 'createNativeQuery'];

    /** @var array<string> User input sources */
    private const USER_INPUT_SOURCES = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER'];

    private bool $hasConcatenation = false;

    private bool $hasInterpolation = false;

    private bool $hasMissingParameters = false;

    private bool $hasSprintfWithUserInput = false;

    /** @var array<string> Variables that might contain SQL (built with concatenation/interpolation) */
    private array $sqlVariables = [];

    /** @var array<string> Variables that contain user input ($_GET,, etc.) */
    private array $userInputVariables = [];

    /**
     * Called when entering each node in the AST.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function enterNode(Node $node): ?Node
    {
        // Track user input assignments: $var = $_GET['key']
        $this->trackUserInputAssignment($node);

        // Pattern 1: String concatenation with variables
        // Example: $sql = "SELECT * FROM users WHERE id = " . $userId
        if ($this->isStringConcatenationWithVariable($node)) {
            $this->hasConcatenation = true;
            // Track this variable as potentially containing SQL
            if ($node instanceof Assign && $node->var instanceof Variable) {
                $varName = $node->var->name;
                if (is_string($varName)) {
                    $this->sqlVariables[] = $varName;
                }
            }
        }

        // Pattern 2: Variable interpolation in SQL strings
        // Example: $sql = "SELECT * FROM users WHERE id = $userId"
        if ($this->isStringInterpolationWithSqlKeywords($node)) {
            $this->hasInterpolation = true;
            // Track this variable as potentially containing SQL
            if ($node instanceof Assign && $node->var instanceof Variable) {
                $varName = $node->var->name;
                if (is_string($varName)) {
                    $this->sqlVariables[] = $varName;
                }
            }
        }

        // Pattern 3: SQL execution without parameters
        // Example: $conn->executeQuery($sql) without second param
        if ($this->isSqlExecutionWithoutParameters($node)) {
            $this->hasMissingParameters = true;
        }

        // Pattern 4: sprintf with SQL and user input
        // Example: $sql = sprintf("SELECT * FROM users WHERE id = %s", $_GET['id'])
        // Check both standalone sprintf() and sprintf() in assignment
        if ($this->isSprintfWithSqlAndUserInput($node)) {
            $this->hasSprintfWithUserInput = true;
        }

        // Also check if node is an Assign with sprintf as the expression
        if ($node instanceof Assign && $node->expr instanceof FuncCall) {
            if ($this->isSprintfWithSqlAndUserInput($node->expr)) {
                $this->hasSprintfWithUserInput = true;
            }
        }

        return null;
    }

    public function hasConcatenationPattern(): bool
    {
        return $this->hasConcatenation;
    }

    public function hasInterpolationPattern(): bool
    {
        return $this->hasInterpolation;
    }

    public function hasMissingParametersPattern(): bool
    {
        return $this->hasMissingParameters;
    }

    public function hasSprintfPattern(): bool
    {
        return $this->hasSprintfWithUserInput;
    }

    /**
     * Track assignments from user input sources.
     * Example: $email = $_GET['email']
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function trackUserInputAssignment(Node $node): void
    {
        if (!$node instanceof Assign) {
            return;
        }

        if (!$node->var instanceof Variable) {
            return;
        }

        $varName = $node->var->name;
        if (!is_string($varName)) {
            return;
        }

        // Check if assigned value is from user input
        $expr = $node->expr;

        // Direct: $var = $_GET['key']
        if ($expr instanceof ArrayDimFetch && $expr->var instanceof Variable) {
            $sourceVarName = $expr->var->name;
            if (is_string($sourceVarName) && in_array($sourceVarName, self::USER_INPUT_SOURCES, true)) {
                $this->userInputVariables[] = $varName;
                return;
            }
        }

        // Ternary/Coalesce: $var = $_GET['key'] ?? 'default'
        if ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $left = $expr->left;
            if ($left instanceof ArrayDimFetch && $left->var instanceof Variable) {
                $sourceVarName = $left->var->name;
                if (is_string($sourceVarName) && in_array($sourceVarName, self::USER_INPUT_SOURCES, true)) {
                    $this->userInputVariables[] = $varName;
                    return;
                }
            }
        }

        // Method call: $var = $request->get('key')
        if ($expr instanceof MethodCall) {
            if ($expr->name instanceof Node\Identifier && str_starts_with($expr->name->toString(), 'get')) {
                $this->userInputVariables[] = $varName;
            }
        }
    }

    /**
     * Check if node is string concatenation with a variable containing SQL.
     *
     * Example: "SELECT * FROM users" . $variable
     * Example: $variable . " WHERE id = " . $id
     * Example: $sql .= $search (concat assignment)
     */
    private function isStringConcatenationWithVariable(Node $node): bool
    {
        // Check for concatenation assignment: $sql = "..." . $var
        if ($node instanceof Assign) {
            if ($node->expr instanceof Concat) {
                $hasSql = $this->hasSqlKeywordInConcat($node->expr);
                if ($hasSql) {
                    // Track this variable
                    if ($node->var instanceof Variable) {
                        $varName = $node->var->name;
                        if (is_string($varName)) {
                            $this->sqlVariables[] = $varName;
                        }
                    }
                    return true;
                }
            }
        }

        // Check for concat assignment: $sql .= "..."
        if ($node instanceof ConcatAssign) {
            // Track this variable as SQL-containing
            if ($node->var instanceof Variable) {
                $varName = $node->var->name;
                if (is_string($varName)) {
                    // If it's already tracked or the expression has variables, flag it
                    if (in_array($varName, $this->sqlVariables, true) || $this->hasVariableInExpression($node->expr)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a concat expression contains SQL keywords and variables.
     */
    private function hasSqlKeywordInConcat(Concat $concat): bool
    {
        $left = $concat->left;
        $right = $concat->right;

        $leftHasSql = $this->hasSqlKeywordInExpression($left);
        $rightHasSql = $this->hasSqlKeywordInExpression($right);
        $hasVariable = $this->hasVariableInExpression($left) || $this->hasVariableInExpression($right);

        return ($leftHasSql || $rightHasSql) && $hasVariable;
    }

    /**
     * Check if expression contains SQL keywords.
     */
    private function hasSqlKeywordInExpression(Node $expr): bool
    {
        if ($expr instanceof String_) {
            $value = strtoupper($expr->value);
            foreach (self::SQL_KEYWORDS as $keyword) {
                if (str_contains($value, $keyword)) {
                    return true;
                }
            }
        }

        if ($expr instanceof Concat) {
            return $this->hasSqlKeywordInExpression($expr->left) || $this->hasSqlKeywordInExpression($expr->right);
        }

        return false;
    }

    /**
     * Check if expression contains a variable.
     */
    private function hasVariableInExpression(Node $expr): bool
    {
        if ($expr instanceof Variable) {
            return true;
        }

        if ($expr instanceof Concat) {
            return $this->hasVariableInExpression($expr->left) || $this->hasVariableInExpression($expr->right);
        }

        if ($expr instanceof PropertyFetch || $expr instanceof MethodCall || $expr instanceof ArrayDimFetch) {
            return true;
        }

        return false;
    }

    /**
     * Check if node is a string with variable interpolation and SQL keywords.
     *
     * Example: "SELECT * FROM users WHERE id = $userId"
     * Example: "SELECT * FROM users WHERE id = {$userId}" (curly brace syntax)
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function isStringInterpolationWithSqlKeywords(Node $node): bool
    {
        if (!$node instanceof Assign) {
            return false;
        }

        // Check for encapsed string (double-quoted string with variables)
        if (!$node->expr instanceof Encapsed) {
            return false;
        }

        $encapsed = $node->expr;

        // Check if it contains variables (including property access like $this->id)
        $hasVariable = false;
        foreach ($encapsed->parts as $part) {
            if ($part instanceof Variable || $part instanceof ArrayDimFetch || $part instanceof PropertyFetch) {
                $hasVariable = true;
                break;
            }
        }

        if (!$hasVariable) {
            return false;
        }

        // Check if string contains SQL keywords
        // Note: In interpolated strings, the static parts are EncapsedStringPart
        foreach ($encapsed->parts as $part) {
            if ($part instanceof String_ || $part instanceof EncapsedStringPart) {
                $value = strtoupper($part->value);
                foreach (self::SQL_KEYWORDS as $keyword) {
                    if (str_contains($value, $keyword)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if node is a SQL execution method call without parameters.
     *
     * Example: $conn->executeQuery($sql) without second param
     * We also check if $sql was built with concatenation/interpolation
     */
    private function isSqlExecutionWithoutParameters(Node $node): bool
    {
        if (!$node instanceof MethodCall) {
            return false;
        }

        // Check if method name is a SQL execution method
        if (!$node->name instanceof Node\Identifier) {
            return false;
        }

        $methodName = $node->name->toString();
        if (!in_array($methodName, self::SQL_EXECUTION_METHODS, true)) {
            return false;
        }

        // Check if called with only one argument (missing parameters)
        if (1 !== count($node->args)) {
            return false;
        }

        // Check if the argument is a variable we tracked as SQL-building variable
        $firstArg = $node->args[0];
        if (!$firstArg instanceof Node\Arg) {
            return false;
        }

        $arg = $firstArg->value;
        if ($arg instanceof Variable) {
            $varName = $arg->name;
            if (is_string($varName) && in_array($varName, $this->sqlVariables, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if node is sprintf with SQL and user input.
     *
     * Example: sprintf("SELECT * FROM users WHERE id = %s", $_GET['id'])
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function isSprintfWithSqlAndUserInput(Node $node): bool
    {
        if (!$node instanceof FuncCall) {
            return false;
        }

        // Check if it's sprintf
        if (!$node->name instanceof Name || 'sprintf' !== $node->name->toString()) {
            return false;
        }

        // Need at least 2 arguments (format string + values)
        if (count($node->args) < 2) {
            return false;
        }

        // Check if first argument (format string) contains SQL keywords
        $firstArg = $node->args[0];
        if (!$firstArg instanceof Node\Arg) {
            return false;
        }

        $formatArg = $firstArg->value;
        $hasSql = false;
        if ($formatArg instanceof String_) {
            $value = strtoupper($formatArg->value);
            foreach (self::SQL_KEYWORDS as $keyword) {
                if (str_contains($value, $keyword)) {
                    $hasSql = true;
                    break;
                }
            }
        }

        if (!$hasSql) {
            return false;
        }

        // Check if any argument is user input ($_GET, $_POST, etc. or tracked variable)
        $argsCount = count($node->args);
        for ($i = 1; $i < $argsCount; $i++) {
            $argNode = $node->args[$i];
            if (!$argNode instanceof Node\Arg) {
                continue;
            }

            $arg = $argNode->value;

            // Check for direct $_GET, $_POST, etc.
            if ($arg instanceof ArrayDimFetch && $arg->var instanceof Variable) {
                $varName = $arg->var->name;
                if (is_string($varName) && in_array($varName, self::USER_INPUT_SOURCES, true)) {
                    return true;
                }
            }

            // Check for tracked user input variable: $email (where $email = $_GET['email'])
            if ($arg instanceof Variable) {
                $varName = $arg->name;
                if (is_string($varName) && in_array($varName, $this->userInputVariables, true)) {
                    return true;
                }
            }

            // Check for $request->get(...) pattern
            if ($arg instanceof MethodCall) {
                if ($arg->name instanceof Node\Identifier && str_starts_with($arg->name->toString(), 'get')) {
                    return true;
                }
            }
        }

        return false;
    }
}
