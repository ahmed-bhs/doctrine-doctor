<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoMappingArrayAccessRule>
 */
final class NoMappingArrayAccessRuleTest extends RuleTestCase
{
    public function test_it_reports_array_access_on_doctrine_mapping_objects(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/PHPStan/mapping-array-access.php'], [
            ['Array access on Doctrine\ORM\Mapping\AssociationMapping is removed in Doctrine ORM 4: use its properties or MappingHelper.', 30],
            ['Array access on Doctrine\ORM\Mapping\FieldMapping is removed in Doctrine ORM 4: use its properties or MappingHelper.', 31],
            ['Array access on Doctrine\ORM\Mapping\JoinColumnMapping is removed in Doctrine ORM 4: use its properties or MappingHelper.', 32],
            ['Array access on Doctrine\ORM\Mapping\AssociationMapping is removed in Doctrine ORM 4: use its properties or MappingHelper.', 34],
            ['Array access on Doctrine\ORM\Mapping\FieldMapping is removed in Doctrine ORM 4: use its properties or MappingHelper.', 35],
            ['Array access on Doctrine\ORM\Mapping\AssociationMapping is removed in Doctrine ORM 4: use its properties or MappingHelper.', 38],
        ]);
    }

    protected function getRule(): Rule
    {
        return new NoMappingArrayAccessRule();
    }
}
