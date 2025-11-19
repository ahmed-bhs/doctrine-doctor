<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\SensitiveDataExposureVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class SensitiveDataExposureVisitorTest extends TestCase
{
    public function test_detects_json_encode_of_this(): void
    {
        $code = 'public function __toString(): string {
            return json_encode($this);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertTrue($exposesObject, 'Should detect json_encode($this)');
    }

    public function test_detects_serialize_of_this(): void
    {
        $code = '
        public function __toString(): string {
            return serialize($this);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertTrue($exposesObject, 'Should detect serialize($this)');
    }

    public function test_ignores_json_encode_with_different_variable(): void
    {
        $code = '
        public function __toString(): string {
            $data = ["id" => $this->id];
            return json_encode($data); // Safe, only specific fields
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertFalse($exposesObject, 'Should NOT flag json_encode($data)');
    }

    public function test_ignores_comments(): void
    {
        $code = '
        public function __toString(): string {
            // Never use json_encode($this) in production!
            /* Also avoid serialize($this) */
            return $this->name;
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertFalse($exposesObject, 'Should ignore comments');
    }

    public function test_ignores_string_literals(): void
    {
        $code = '
        public function logWarning(): void {
            $message = "Do not use json_encode($this)";
            echo $message;
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertFalse($exposesObject, 'Should ignore string literals');
    }

    public function test_ignores_safe_to_string(): void
    {
        $code = '
        public function __toString(): string {
            return $this->name . " (" . $this->id . ")";
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertFalse($exposesObject, 'Should not flag safe __toString()');
    }

    public function test_detects_json_encode_with_spacing(): void
    {
        $code = '
        public function __toString(): string {
            return json_encode(  $this  );
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertTrue($exposesObject, 'Should handle various spacing');
    }

    public function test_ignores_json_encode_of_other_object(): void
    {
        $code = '
        public function toJson(): string {
            $dto = $this->toDTO();
            return json_encode($dto);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertFalse($exposesObject, 'Should not flag DTO serialization');
    }

    public function test_ignores_serialize_of_array(): void
    {
        $code = '
        public function export(): string {
            $data = [$this->id, $this->name];
            return serialize($data);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        self::assertFalse($exposesObject, 'Should not flag array serialization');
    }

    /**
     * Helper method to detect sensitive exposure in PHP code.
     */
    private function detectSensitiveExposure(string $code): bool
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // Wrap in class context for parser
        $wrappedCode = "<?php\nclass Test {\n" . $code . "\n}\n";
        $ast = $parser->parse($wrappedCode);
        self::assertIsArray($ast, 'Parser should return an array');

        $visitor = new SensitiveDataExposureVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->exposesEntireObject();
    }
}
