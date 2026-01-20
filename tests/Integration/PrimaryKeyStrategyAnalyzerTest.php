<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\PrimaryKeyStrategyAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyStrategyAnalyzerTest extends TestCase
{
    private PrimaryKeyStrategyAnalyzer $primaryKeyStrategyAnalyzer;

    private MockObject $entityManager;

    protected function setUp(): void
    {
        $inMemoryTemplateRenderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($inMemoryTemplateRenderer);

        // Mock EntityManager
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->primaryKeyStrategyAnalyzer = new PrimaryKeyStrategyAnalyzer(
            $this->entityManager,
            $suggestionFactory,
        );
    }

    public function test_detects_auto_increment_on_user_entity(): void
    {
        $classMetadata = $this->createAutoIncrementMetadata('App\\Entity\\User', 'User'); // @phpstan-ignore-line
        $classMetadataFactory = $this->createMetadataFactory([$classMetadata]);

        $this->entityManager
            ->expects(self::once())
            ->method('getMetadataFactory')
            ->willReturn($classMetadataFactory);

        $issueCollection = $this->primaryKeyStrategyAnalyzer->analyze();

        // Should detect auto-increment on User entity (UUID candidate)
        self::assertGreaterThanOrEqual(1, $issueCollection->count());

        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('User', $issue->getTitle());
        self::assertStringContainsString('Auto-Increment', $issue->getTitle());
        self::assertSame('info', $issue->getSeverity()->value);
    }

    public function test_ignores_auto_increment_on_non_candidate_entity(): void
    {
        $classMetadata = $this->createAutoIncrementMetadata('App\\Entity\\Product', 'Product'); // @phpstan-ignore-line
        $classMetadataFactory = $this->createMetadataFactory([$classMetadata]);

        $this->entityManager
            ->expects(self::once())
            ->method('getMetadataFactory')
            ->willReturn($classMetadataFactory);

        $issueCollection = $this->primaryKeyStrategyAnalyzer->analyze();

        // Should not report Product (not a UUID candidate like User/Session)
        self::assertCount(0, $issueCollection);
    }

    public function test_detects_uuid_v4_usage(): void
    {
        $classMetadata = $this->createUuidV4Metadata('App\\Entity\\User', 'User'); // @phpstan-ignore-line
        $classMetadataFactory = $this->createMetadataFactory([$classMetadata]);

        $this->entityManager
            ->expects(self::once())
            ->method('getMetadataFactory')
            ->willReturn($classMetadataFactory);

        $issueCollection = $this->primaryKeyStrategyAnalyzer->analyze();

        // Should suggest UUID v7 instead of v4
        self::assertGreaterThanOrEqual(1, $issueCollection->count());

        $foundV4Issue = false;
        foreach ($issueCollection as $issue) {
            if (str_contains($issue->getTitle(), 'UUID v4')) {
                $foundV4Issue = true;
                self::assertStringContainsString('v7', $issue->getDescription());
                self::assertSame('info', $issue->getSeverity()->value);
                break;
            }
        }

        self::assertTrue($foundV4Issue, 'Should detect UUID v4 usage');
    }

    public function test_detects_mixed_strategies(): void
    {
        $classMetadata = $this->createAutoIncrementMetadata('App\\Entity\\Product', 'Product'); // @phpstan-ignore-line
        $uuidMetadata = $this->createUuidV4Metadata('App\\Entity\\User', 'User'); // @phpstan-ignore-line

        $classMetadataFactory = $this->createMetadataFactory([$classMetadata, $uuidMetadata]);

        $this->entityManager
            ->expects(self::once())
            ->method('getMetadataFactory')
            ->willReturn($classMetadataFactory);

        $issueCollection = $this->primaryKeyStrategyAnalyzer->analyze();

        // Should report mixed strategies
        $foundMixedIssue = false;
        foreach ($issueCollection as $issue) {
            if (str_contains($issue->getTitle(), 'Mixed')) {
                $foundMixedIssue = true;
                self::assertStringContainsString('auto-increment', $issue->getDescription());
                self::assertStringContainsString('UUIDs', $issue->getDescription());
                self::assertSame('info', $issue->getSeverity()->value);
                break;
            }
        }

        self::assertTrue($foundMixedIssue, 'Should detect mixed strategies');
    }

    public function test_ignores_mapped_superclass(): void
    {
        $classMetadata = $this->createAutoIncrementMetadata('App\\Entity\\BaseEntity', 'BaseEntity'); // @phpstan-ignore-line
        $classMetadata->isMappedSuperclass = true;

        $classMetadataFactory = $this->createMetadataFactory([$classMetadata]);

        $this->entityManager
            ->expects(self::once())
            ->method('getMetadataFactory')
            ->willReturn($classMetadataFactory);

        $issueCollection = $this->primaryKeyStrategyAnalyzer->analyze();

        // Should ignore mapped superclasses
        self::assertCount(0, $issueCollection);
    }

    public function test_all_issues_have_info_severity(): void
    {
        $classMetadata = $this->createAutoIncrementMetadata('App\\Entity\\User', 'User'); // @phpstan-ignore-line
        $sessionMetadata = $this->createUuidV4Metadata('App\\Entity\\Session', 'Session'); // @phpstan-ignore-line
        $productMetadata = $this->createAutoIncrementMetadata('App\\Entity\\Product', 'Product'); // @phpstan-ignore-line

        $classMetadataFactory = $this->createMetadataFactory([$classMetadata, $sessionMetadata, $productMetadata]);

        $this->entityManager
            ->expects(self::once())
            ->method('getMetadataFactory')
            ->willReturn($classMetadataFactory);

        $issueCollection = $this->primaryKeyStrategyAnalyzer->analyze();

        // All issues should be INFO (educational)
        foreach ($issueCollection as $issue) {
            self::assertSame(
                'info',
                $issue->getSeverity()->value,
                sprintf('Issue "%s" should have INFO severity', $issue->getTitle()),
            );
        }
    }

    /**
     * Create metadata with auto-increment strategy.
     *
     * @param class-string $className
     */
    private function createAutoIncrementMetadata(string $className, string $shortName): ClassMetadata
    {
        $classMetadata = new ClassMetadata($className);
        $classMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_IDENTITY;
        $classMetadata->isMappedSuperclass = false;
        $classMetadata->isEmbeddedClass = false;

        // Mock reflection
        $reflectionClass = $this->createMock(\ReflectionClass::class);
        $reflectionClass->method('getShortName')->willReturn($shortName);
        $reflectionClass->method('getFileName')->willReturn('/fake/path/' . $shortName . '.php');
        $reflectionClass->method('getStartLine')->willReturn(10);

        $classMetadata->reflClass = $reflectionClass;

        return $classMetadata;
    }

    /**
     * Create metadata with UUID v4 strategy.
     *
     * @param class-string $className
     */
    private function createUuidV4Metadata(string $className, string $shortName): ClassMetadata
    {
        $classMetadata = new ClassMetadata($className);
        $classMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_CUSTOM;
        $classMetadata->customGeneratorDefinition = [
            'class' => 'Symfony\\Bridge\\Doctrine\\IdGenerator\\UuidV4Generator',
        ];
        $classMetadata->isMappedSuperclass = false;
        $classMetadata->isEmbeddedClass = false;

        // Mock reflection
        $reflectionClass = $this->createMock(\ReflectionClass::class);
        $reflectionClass->method('getShortName')->willReturn($shortName);
        $reflectionClass->method('getFileName')->willReturn('/fake/path/' . $shortName . '.php');
        $reflectionClass->method('getStartLine')->willReturn(10);

        $classMetadata->reflClass = $reflectionClass;

        return $classMetadata;
    }

    /**
     * Create metadata factory with given metadata.
     *
     * @param array<ClassMetadata> $allMetadata
     */
    private function createMetadataFactory(array $allMetadata): ClassMetadataFactory
    {
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn($allMetadata);

        return $metadataFactory;
    }
}
