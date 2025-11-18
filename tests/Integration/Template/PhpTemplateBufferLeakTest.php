<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Template;

use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Integration test to reproduce HTML buffer leak in JSON responses
 *
 * BUG: When a PHP template uses ob_start()/ob_get_clean() and an error occurs
 * between these calls, the HTML buffer is never cleaned and leaks into HTTP response.
 *
 * Scenario:
 * 1. Template starts with ob_start()
 * 2. Template generates HTML
 * 3. ERROR occurs (e.g., TypeError with htmlspecialchars on int)
 * 4. ob_get_clean() is never called
 * 5. HTML buffer leaks into response
 */
final class PhpTemplateBufferLeakTest extends TestCase
{
    private string $templateDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary template directory
        $this->templateDirectory = sys_get_temp_dir() . '/doctrine_doctor_test_templates_' . uniqid();
        if (!is_dir($this->templateDirectory)) {
            mkdir($this->templateDirectory, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (is_dir($this->templateDirectory)) {
            array_map('unlink', glob($this->templateDirectory . '/*'));
            rmdir($this->templateDirectory);
        }

        // Clean any remaining output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_should_not_leak_html_buffer_on_template_error(): void
    {
        // Create a template that simulates the bug
        $templateContent = <<<'PHP'
<?php
declare(strict_types=1);

$count = $context['count'] ?? 0;

ob_start();
?>
<div class="test-template">
    <h2>Test</h2>
    <p>Count: <?= htmlspecialchars($count) ?></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Test template',
];
PHP;

        file_put_contents($this->templateDirectory . '/test_template.php', $templateContent);

        $renderer = new PhpTemplateRenderer($this->templateDirectory);

        // Capture current output buffer level
        $bufferLevelBefore = ob_get_level();

        try {
            // This will trigger TypeError because count is int, not string
            $renderer->render('test_template', ['count' => 123]);

            // If we get here, the bug might be "fixed" by PHP auto-conversion
            // Check that no HTML leaked
            $this->assertSame($bufferLevelBefore, ob_get_level(), 'Output buffer level should be unchanged');

        } catch (\Throwable $e) {
            // Error occurred - this is expected
            // BUT: check that HTML buffer was cleaned
            $bufferLevelAfter = ob_get_level();

            $this->assertSame(
                $bufferLevelBefore,
                $bufferLevelAfter,
                'Output buffer should be cleaned even when template throws error. Buffer leak detected!'
            );

            // Verify no HTML was output
            $output = '';
            while (ob_get_level() > $bufferLevelBefore) {
                $output .= ob_get_clean();
            }

            $this->assertEmpty(
                $output,
                'No HTML should leak into output buffer. Found: ' . substr($output, 0, 200)
            );
        }
    }

    /**
     * @test
     */
    public function it_reproduces_primary_key_mixed_template_bug(): void
    {
        // Copy the actual problematic template
        $templateContent = file_get_contents(
            dirname(__DIR__, 3) . '/src/Template/Suggestions/Integrity/primary_key_mixed.php'
        );

        file_put_contents($this->templateDirectory . '/Integrity', '');
        unlink($this->templateDirectory . '/Integrity');
        mkdir($this->templateDirectory . '/Integrity');
        file_put_contents($this->templateDirectory . '/Integrity/primary_key_mixed.php', $templateContent);

        $renderer = new PhpTemplateRenderer($this->templateDirectory);

        $bufferLevelBefore = ob_get_level();

        try {
            // Trigger with int values (the bug)
            $result = $renderer->render('Integrity/primary_key_mixed', [
                'auto_increment_count' => 10,  // INT - will cause TypeError
                'uuid_count' => 5,              // INT - will cause TypeError
                'auto_increment_entities' => ['User', 'Product'],
                'uuid_entities' => ['Session'],
            ]);

            // If successful, verify result doesn't contain raw HTML buffer
            $this->assertIsArray($result);
            $this->assertArrayHasKey('code', $result);

        } catch (\Throwable $e) {
            // Check buffer was cleaned
            $bufferLevelAfter = ob_get_level();

            $leaked = '';
            while (ob_get_level() > $bufferLevelBefore) {
                $leaked .= ob_get_clean();
            }

            $this->fail(
                "Template threw error AND leaked HTML buffer:\n" .
                "Error: {$e->getMessage()}\n" .
                "Leaked HTML: " . substr($leaked, 0, 500)
            );
        }
    }

    /**
     * @test
     */
    public function it_should_capture_http_response_with_leaked_html(): void
    {
        // Simulate what happens in a real HTTP response
        $this->markTestIncomplete(
            'This test simulates the full HTTP flow where JSON response gets HTML appended. ' .
            'Run the route /api/test/mixed-primary-keys to see the bug in action.'
        );
    }
}
