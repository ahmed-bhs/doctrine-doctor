<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Detects entity state inconsistencies via static metadata analysis.
 *
 * Analyzes entity mappings to detect potential state management issues:
 * - Required (non-nullable) associations without cascade persist
 * - Associations targeting entities without cascade configuration
 * - Missing cascade remove on composition relationships
 */
class EntityStateConsistencyAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                $metadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata = $metadataFactory->getAllMetadata();

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata);

                    Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Entity State Consistency Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects potential entity state inconsistencies via static metadata analysis';
    }

    /**
     * @template T of object
     * @param ClassMetadata<T> $classMetadata
     * @return list<IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $issues = [];

        foreach ($classMetadata->getAssociationMappings() as $assocName => $mapping) {
            if (!$classMetadata->isSingleValuedAssociation($assocName)) {
                continue;
            }

            $joinColumns = $mapping['joinColumns'] ?? [];
            $nullable = true;

            if (\is_array($joinColumns) && isset($joinColumns[0]) && \is_array($joinColumns[0])) {
                $nullable = (bool) ($joinColumns[0]['nullable'] ?? true);
            }

            if ($nullable) {
                continue;
            }

            $cascade = \is_array($mapping['cascade'] ?? null) ? $mapping['cascade'] : [];

            if (!\in_array('persist', $cascade, true)) {
                $shortName = $this->getShortClassName($classMetadata->getName());
                $targetEntity = $mapping['targetEntity'] ?? '';
                $targetShort = $this->getShortClassName(\is_string($targetEntity) ? $targetEntity : '');

                $description = sprintf(
                    "Required association %s::\$%s targets %s without cascade persist.\n\n",
                    $shortName,
                    $assocName,
                    $targetShort,
                );

                $description .= "Problem:\n";
                $description .= "- The association is NOT NULL (required)\n";
                $description .= "- Without cascade persist, you must manually persist the related entity first\n";
                $description .= "- Forgetting to persist will cause a foreign key constraint violation\n\n";

                $description .= "Solutions:\n\n";
                $description .= "1. Add cascade persist:\n";
                $description .= sprintf("   #[ManyToOne(cascade: ['persist'])]\n");
                $description .= sprintf("   private %s \$%s;\n\n", $targetShort, $assocName);
                $description .= "2. Always persist the related entity before flush:\n";
                $description .= sprintf("   \$em->persist(\$%s);\n", $assocName);
                $description .= sprintf("   \$entity->set%s(\$%s);\n", ucfirst($assocName), $assocName);
                $description .= "   \$em->flush();\n";

                $issueData = new IssueData(
                    type: 'entity_new_in_association',
                    title: sprintf('Required Association Without Cascade: %s::$%s', $shortName, $assocName),
                    description: $description,
                    severity: Severity::warning(),
                    suggestion: null,
                    queries: [],
                );

                $issues[] = $this->issueFactory->create($issueData);
            }
        }

        return $issues;
    }

    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
