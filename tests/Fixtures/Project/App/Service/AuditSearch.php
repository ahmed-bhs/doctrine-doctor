<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Project\App\Service;

use Doctrine\DBAL\Connection;

final class AuditSearch
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function byAction(string $action): array
    {
        return $this->connection->fetchAllAssociative("SELECT subject FROM audit_log WHERE action = '" . $action . "'");
    }
}
