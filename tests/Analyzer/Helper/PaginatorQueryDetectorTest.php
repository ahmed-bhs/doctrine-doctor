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
    private PaginatorQueryDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PaginatorQueryDetector();
    }

    #[Test]
    public function it_detects_doctrine_paginator_in_backtrace(): void
    {
        $backtrace = [
            ['file' => '/app/vendor/doctrine/orm/src/AbstractQuery.php', 'line' => 724, 'class' => \Doctrine\ORM\AbstractQuery::class, 'function' => 'getScalarResult'],
            ['file' => '/app/vendor/doctrine/orm/src/Tools/Pagination/Paginator.php', 'line' => 96, 'class' => \Doctrine\ORM\Tools\Pagination\Paginator::class, 'function' => 'count'],
        ];

        self::assertTrue($this->detector->isPaginatorQuery($backtrace));
    }

    #[Test]
    public function it_detects_orm_offset_paginator_in_backtrace(): void
    {
        // ORM 3.7 deprecates Paginator in favour of OffsetPaginator.
        $backtrace = [
            ['file' => '/app/vendor/doctrine/orm/src/AbstractQuery.php', 'line' => 724, 'class' => \Doctrine\ORM\AbstractQuery::class, 'function' => 'getScalarResult'],
            ['file' => '/app/vendor/doctrine/orm/src/Tools/Pagination/OffsetPaginator.php', 'line' => 89, 'class' => 'Doctrine\\ORM\\Tools\\Pagination\\OffsetPaginator', 'function' => 'paginate'],
        ];

        self::assertTrue($this->detector->isPaginatorQuery($backtrace));
    }

    #[Test]
    public function it_detects_orm_cursor_paginator_in_backtrace(): void
    {
        // CursorPage counts lazily, from a closure declared in CursorPaginator.
        $backtrace = [
            ['file' => '/app/vendor/doctrine/orm/src/AbstractQuery.php', 'line' => 724, 'class' => \Doctrine\ORM\AbstractQuery::class, 'function' => 'getScalarResult'],
            ['file' => '/app/vendor/doctrine/orm/src/Tools/Pagination/CursorPaginator.php', 'line' => 154, 'class' => 'Doctrine\\ORM\\Tools\\Pagination\\CursorPaginator', 'function' => '{closure}'],
            ['file' => '/app/vendor/doctrine/orm/src/Tools/Pagination/CursorPage.php', 'line' => 79, 'class' => 'Doctrine\\ORM\\Tools\\Pagination\\CursorPage', 'function' => 'getTotalCount'],
        ];

        self::assertTrue($this->detector->isPaginatorQuery($backtrace));
    }

    #[Test]
    public function it_detects_easyadmin_entity_paginator_in_backtrace(): void
    {
        $backtrace = [
            ['file' => '/app/vendor/easycorp/easyadmin-bundle/src/Orm/EntityPaginator.php', 'line' => 72, 'class' => 'EasyCorp\Bundle\EasyAdminBundle\Orm\EntityPaginator', 'function' => 'paginate'],
        ];

        self::assertTrue($this->detector->isPaginatorQuery($backtrace));
    }

    #[Test]
    public function it_returns_false_for_null_backtrace(): void
    {
        self::assertFalse($this->detector->isPaginatorQuery(null));
    }

    #[Test]
    public function it_returns_false_for_empty_backtrace(): void
    {
        self::assertFalse($this->detector->isPaginatorQuery([]));
    }

    #[Test]
    public function it_returns_false_for_non_paginator_backtrace(): void
    {
        $backtrace = [
            ['file' => '/app/src/Repository/UserRepository.php', 'line' => 42, 'class' => 'App\Repository\UserRepository', 'function' => 'findAll'],
            ['file' => '/app/src/Controller/UserController.php', 'line' => 18, 'class' => 'App\Controller\UserController', 'function' => 'index'],
        ];

        self::assertFalse($this->detector->isPaginatorQuery($backtrace));
    }
}
