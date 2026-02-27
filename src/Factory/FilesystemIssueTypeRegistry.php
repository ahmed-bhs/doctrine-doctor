<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\Issue\AbstractIssue;
use InvalidArgumentException;

final class FilesystemIssueTypeRegistry implements IssueTypeRegistryInterface
{
    /** @var array<string, class-string<AbstractIssue>>|null */
    private ?array $typeMap = null;

    /**
     * @return array<string, class-string<AbstractIssue>>
     */
    public function getTypeMap(): array
    {
        if (null !== $this->typeMap) {
            return $this->typeMap;
        }

        $map = [];
        foreach ($this->discoverIssueClasses() as $issueClass) {
            foreach ($issueClass::supportedTypes() as $type) {
                if (isset($map[$type]) && $map[$type] !== $issueClass) {
                    throw new InvalidArgumentException(sprintf(
                        'Duplicate issue type mapping for "%s": %s and %s',
                        $type,
                        $map[$type],
                        $issueClass,
                    ));
                }

                $map[$type] = $issueClass;
            }
        }

        $this->typeMap = $map;

        return $this->typeMap;
    }

    /**
     * @return list<string>
     */
    public function getSupportedTypes(): array
    {
        return array_keys($this->getTypeMap());
    }

    public function supports(string $type): bool
    {
        return isset($this->getTypeMap()[$type]);
    }

    /**
     * @return list<class-string<AbstractIssue>>
     */
    private function discoverIssueClasses(): array
    {
        $issueClasses = [];
        $issueFiles = glob(__DIR__ . '/../Issue/*Issue.php') ?: [];

        foreach ($issueFiles as $issueFile) {
            $className = pathinfo($issueFile, PATHINFO_FILENAME);
            $fqcn = 'AhmedBhs\\DoctrineDoctor\\Issue\\' . $className;

            if (!class_exists($fqcn)) {
                continue;
            }

            if (!is_subclass_of($fqcn, AbstractIssue::class)) {
                continue;
            }

            $issueClasses[] = $fqcn;
        }

        return $issueClasses;
    }
}
