<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\EnumCandidateTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'enum_document')]
class Document extends BaseDocument
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currencyCode = 'EUR';

    #[ORM\Column(type: 'string', length: 60)]
    private string $mimeType = 'application/pdf';

    #[ORM\Column(type: 'string', length: 20)]
    private string $kind = 'invoice';
}
