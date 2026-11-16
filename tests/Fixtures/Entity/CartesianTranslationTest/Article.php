<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CartesianTranslationTest;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ct_article')]
class Article
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    /** @var Collection<int, ArticleTranslation> */
    #[ORM\OneToMany(targetEntity: ArticleTranslation::class, mappedBy: 'translatable')]
    public Collection $translations;

    /** @var Collection<int, ArticleComment> */
    #[ORM\OneToMany(targetEntity: ArticleComment::class, mappedBy: 'article')]
    public Collection $comments;
}
