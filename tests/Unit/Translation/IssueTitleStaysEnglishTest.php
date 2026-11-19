<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;

/**
 * An issue title is part of the finding ID kept in a baseline: it must be built in English and only translated
 * when it is shown, or a baseline generated in one language stops matching in another.
 */
final class IssueTitleStaysEnglishTest extends TestCase
{
    public function test_no_issue_title_is_translated_when_it_is_built(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $offenders = [];

        foreach (['Analyzer', 'Infrastructure', 'Issue'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                foreach (file($file->getPathname()) ?: [] as $number => $line) {
                    if (1 === preg_match('/(\'title\'\s*=>|\btitle:|setTitle\().*Translator::/', $line)) {
                        $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($number + 1);
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Build these titles in English and add their French text to translations/fr.php');
    }
}
