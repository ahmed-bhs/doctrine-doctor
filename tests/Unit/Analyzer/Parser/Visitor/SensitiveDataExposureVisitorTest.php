<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\SensitiveDataExposureVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class SensitiveDataExposureVisitorTest extends TestCase
{
    public function testDetectsJsonEncodeOfThis(): void
    {
        $code = 'public function __toString(): string {
            return json_encode($this);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertTrue($exposesObject, 'Should detect json_encode($this)');
    }

    public function testDetectsSerializeOfThis(): void
    {
        $code = '
        public function __toString(): string {
            return serialize($this);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertTrue($exposesObject, 'Should detect serialize($this)');
    }

    public function testIgnoresJsonEncodeWithDifferentVariable(): void
    {
        $code = '
        public function __toString(): string {
            $data = ["id" => $this->id];
            return json_encode($data); // Safe, only specific fields
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertFalse($exposesObject, 'Should NOT flag json_encode($data)');
    }

    public function testIgnoresComments(): void
    {
        $code = '
        public function __toString(): string {
            // Never use json_encode($this) in production!
            /* Also avoid serialize($this) */
            return $this->name;
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertFalse($exposesObject, 'Should ignore comments');
    }

    public function testIgnoresStringLiterals(): void
    {
        $code = '
        public function logWarning(): void {
            $message = "Do not use json_encode($this)";
            echo $message;
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertFalse($exposesObject, 'Should ignore string literals');
    }

    public function testIgnoresSafeToString(): void
    {
        $code = '
        public function __toString(): string {
            return $this->name . " (" . $this->id . ")";
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertFalse($exposesObject, 'Should not flag safe __toString()');
    }

    public function testDetectsJsonEncodeWithSpacing(): void
    {
        $code = '
        public function __toString(): string {
            return json_encode(  $this  );
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertTrue($exposesObject, 'Should handle various spacing');
    }

    public function testIgnoresJsonEncodeOfOtherObject(): void
    {
        $code = '
        public function toJson(): string {
            $dto = $this->toDTO();
            return json_encode($dto);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertFalse($exposesObject, 'Should not flag DTO serialization');
    }

    public function testIgnoresSerializeOfArray(): void
    {
        $code = '
        public function export(): string {
            $data = [$this->id, $this->name];
            return serialize($data);
        }';

        $exposesObject = $this->detectSensitiveExposure($code);

        $this->assertFalse($exposesObject, 'Should not flag array serialization');
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

        $visitor = new SensitiveDataExposureVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->exposesEntireObject();
    }
}
