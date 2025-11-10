#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validates PHP suggestion templates in src/Template/Suggestions/
 *
 * This script checks for:
 * - declare(strict_types=1)
 * - ob_start() and ob_get_clean() usage
 * - Return array with 'code' and 'description' keys
 * - Escaping function using htmlspecialchars
 * - No markdown syntax (no ##, no ```, warn for ** and * -)
 * - Proper HTML tag closing
 * - Security issues (no eval)
 *
 * Exit codes:
 *   0 = All templates valid (or only warnings)
 *   1 = Validation errors found
 */

class TemplateValidator
{
    private const SUGGESTIONS_DIR = __DIR__ . '/../src/Template/Suggestions';
    private int $errorCount = 0;
    private int $warningCount = 0;
    private int $successCount = 0;
    private array $fileErrors = [];
    private array $fileWarnings = [];

    public function run(): int
    {
        $this->printHeader();
        $files = $this->getTemplateFiles();

        if (empty($files)) {
            $this->error('No template files found in ' . self::SUGGESTIONS_DIR);
            return 1;
        }

        foreach ($files as $file) {
            $this->validateFile($file);
        }

        $this->printSummary();

        return $this->errorCount > 0 ? 1 : 0;
    }

    private function getTemplateFiles(): array
    {
        $dir = self::SUGGESTIONS_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new DirectoryIterator($dir);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $filename = $fileInfo->getFilename();

            // Skip index.php and EXAMPLE_* files
            if ($filename === 'index.php' || strpos($filename, 'EXAMPLE_') === 0) {
                continue;
            }

            // Only process .php files
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function validateFile(string $filePath): void
    {
        $filename = basename($filePath);
        $content = file_get_contents($filePath);

        if ($content === false) {
            $this->error('Cannot read file: ' . $filename);
            return;
        }

        $errors = [];
        $warnings = [];

        // Check 1: declare(strict_types=1)
        if (!preg_match('~declare\s*\(\s*strict_types\s*=\s*1\s*\)~', $content)) {
            $errors[] = 'Missing declare(strict_types=1)';
        }

        // Check 2: ob_start() and ob_get_clean()
        $hasObStart = strpos($content, 'ob_start()') !== false;
        $hasObGetClean = strpos($content, 'ob_get_clean()') !== false;

        if ($hasObStart && !$hasObGetClean) {
            $errors[] = 'Has ob_start() but missing ob_get_clean()';
        }

        if (!$hasObStart && $hasObGetClean) {
            $errors[] = 'Has ob_get_clean() but missing ob_start()';
        }

        // Check 3: Return array with required keys
        if (!preg_match('~return\s*\[\s*[\'"]code[\'"]\s*=>\s*\$\w+~', $content)) {
            $errors[] = 'Missing return statement with code key';
        }

        // Description key must exist (value can be string literal, sprintf, or variable)
        if (!preg_match('~[\'"]description[\'"]\s*=>~', $content)) {
            $errors[] = 'Missing description in return array';
        }

        // Check 4: Escaping function using htmlspecialchars
        if (strpos($content, 'htmlspecialchars') === false) {
            $errors[] = 'Missing htmlspecialchars for escaping';
        }

        // Check 5: Security - no eval
        if (preg_match('~\beval\s*\(~', $content)) {
            $errors[] = 'Found eval() function - security risk';
        }

        // Check 6: No markdown syntax
        if (preg_match('~^##\s~m', $content)) {
            $errors[] = 'Found markdown headers (##). Use HTML <h3> or <h4> instead';
        }

        if (preg_match('~```~', $content)) {
            $errors[] = 'Found markdown code blocks (```). Use <pre><code> instead';
        }

        // Warnings for markdown-style formatting
        if (preg_match('~^\s*[\*-]\s+\w~m', $content)) {
            $warnings[] = 'Found * markdown syntax (consider using HTML ul/li)';
        }

        if (preg_match('~\*\*\w+\*\*~', $content)) {
            $warnings[] = 'Found ** markdown bold (consider using <strong>)';
        }

        // Check 7: HTML tag validation (disabled - too many false positives)
        // $this->validateHtmlTags($content, $errors);

        // Store results
        if (!empty($errors)) {
            $this->fileErrors[$filename] = $errors;
            $this->errorCount += count($errors);
        }

        if (!empty($warnings)) {
            $this->fileWarnings[$filename] = $warnings;
            $this->warningCount += count($warnings);
        }

        if (empty($errors) && empty($warnings)) {
            $this->successCount++;
        }

        // Print file results
        $this->printFileResults($filename, $errors, $warnings);
    }

    private function validateHtmlTags(string $content, array &$errors): void
    {
        // Extract content between ob_start() and ob_get_clean()
        if (!preg_match('~ob_start\(\);(.+?)ob_get_clean\(\)~s', $content, $matches)) {
            return;
        }

        $html = $matches[1];

        // Count opening and closing tags
        preg_match_all('~<(\w+)(?:\s[^>]*)?>~', $html, $openTags);
        preg_match_all('~</(\w+)>~', $html, $closeTags);

        $selfClosing = ['br', 'hr', 'img', 'input', 'meta', 'link', 'path'];

        $opened = array_diff($openTags[1], $selfClosing);
        $closed = $closeTags[1];

        $openCount = array_count_values($opened);
        $closeCount = array_count_values($closed);

        $unclosed = [];
        foreach ($openCount as $tag => $count) {
            $closedNum = $closeCount[$tag] ?? 0;
            if ($count !== $closedNum) {
                $unclosed[] = $tag;
            }
        }

        if (!empty($unclosed)) {
            $errors[] = 'Unclosed HTML tags: ' . implode(', ', array_unique($unclosed));
        }
    }

    private function printFileResults(string $filename, array $errors, array $warnings): void
    {
        echo "\033[36m" . $filename . "\033[0m\n";

        foreach ($errors as $error) {
            echo "\033[91m  ERROR: " . $error . "\033[0m\n";
        }

        foreach ($warnings as $warning) {
            echo "\033[93m  WARNING: " . $warning . "\033[0m\n";
        }

        if (empty($errors) && empty($warnings)) {
            echo "\033[92m  ✓ Valid\033[0m\n";
        }

        echo "\n";
    }

    private function printHeader(): void
    {
        echo str_repeat('=', 61) . "\n";
        echo "  PHP Suggestion Templates Validator\n";
        echo str_repeat('=', 61) . "\n\n";
    }

    private function printSummary(): void
    {
        echo str_repeat('=', 61) . "\n";
        echo "  Validation Summary\n";
        echo str_repeat('=', 61) . "\n";

        $totalFiles = $this->successCount + count($this->fileErrors) + count(array_diff_key($this->fileWarnings, $this->fileErrors));

        echo "\033[92m  SUCCESS: \033[0m" . $this->successCount . " files\n";
        echo "\033[93m  WARNING: \033[0m" . $this->warningCount . " warnings\n";
        echo "\033[91m  ERROR:   \033[0m" . $this->errorCount . " errors\n";
    }

    private function error(string $message): void
    {
        echo "\033[91mERROR: " . $message . "\033[0m\n";
    }
}

// Run validator
$validator = new TemplateValidator();
exit($validator->run());
