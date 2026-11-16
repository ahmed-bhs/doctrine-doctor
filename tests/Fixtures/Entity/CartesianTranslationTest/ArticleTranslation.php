<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CartesianTranslationTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ct_article_translation')]
#[ORM\UniqueConstraint(columns: ['translatable_id', 'locale'])]
class ArticleTranslation
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'translations')]
    public ?Article $translatable = null;

    #[ORM\Column]
    public string $locale = 'en';
}
