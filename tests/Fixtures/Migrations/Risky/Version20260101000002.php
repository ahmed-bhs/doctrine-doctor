<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Migrations\Risky;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000002 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_order ADD exported TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function postUp(Schema $schema): void
    {
        foreach ($this->container->get('doctrine')->getRepository('App\\Entity\\Order')->findBy([]) as $order) {
            $order->markExported();
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_order DROP exported');
    }
}
