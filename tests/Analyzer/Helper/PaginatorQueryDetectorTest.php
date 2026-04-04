<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\PaginatorQueryDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaginatorQueryDetectorTest extends TestCase
{
    #[Test]
    public function it_detects_doctrine_paginator_in_backtrace(): void
    {
        $backtrace = [
            ['file' => '/app/vendor/doctrine/orm/src/AbstractQuery.php', 'line' => 724, 'class' => 'Doctrine\ORM\AbstractQuery', 'function' => 'getScalarResult'],
            ['file' => '/app/vendor/doctrine/orm/src/Tools/Pagination/Paginator.php', 'line' => 96, 'class' => 'Doctrine\ORM\Tools\Pagination\Paginator', 'function' => 'count'],
        ];

        self::assertTrue(PaginatorQueryDetector::isPaginatorQuery($backtrace));
    }

    #[Test]
    public function it_detects_easyadmin_entity_paginator_in_backtrace(): void
    {
        $backtrace = [
            ['file' => '/app/vendor/easycorp/easyadmin-bundle/src/Orm/EntityPaginator.php', 'line' => 72, 'class' => 'EasyCorp\Bundle\EasyAdminBundle\Orm\EntityPaginator', 'function' => 'paginate'],
        ];

        self::assertTrue(PaginatorQueryDetector::isPaginatorQuery($backtrace));
    }

    #[Test]
    public function it_returns_false_for_null_backtrace(): void
    {
        self::assertFalse(PaginatorQueryDetector::isPaginatorQuery(null));
    }

    #[Test]
    public function it_returns_false_for_empty_backtrace(): void
    {
        self::assertFalse(PaginatorQueryDetector::isPaginatorQuery([]));
    }

    #[Test]
    public function it_returns_false_for_non_paginator_backtrace(): void
    {
        $backtrace = [
            ['file' => '/app/src/Repository/UserRepository.php', 'line' => 42, 'class' => 'App\Repository\UserRepository', 'function' => 'findAll'],
            ['file' => '/app/src/Controller/UserController.php', 'line' => 18, 'class' => 'App\Controller\UserController', 'function' => 'index'],
        ];

        self::assertFalse(PaginatorQueryDetector::isPaginatorQuery($backtrace));
    }
}
