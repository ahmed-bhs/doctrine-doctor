<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads realistic Product and Category data for testing.
 */
class ProductFixture implements FixtureInterface
{
    /** @var array<Category> */
    private array $categories = [];

    /** @var array<Product> */
    private array $products = [];

    public function load(EntityManagerInterface $em): void
    {
        // Create categories
        $categoryData = [
            'Electronics' => ['Smartphones', 'Laptops', 'Tablets'],
            'Clothing' => ['Men', 'Women', 'Kids'],
            'Books' => ['Fiction', 'Non-Fiction', 'Technical'],
        ];

        foreach ($categoryData as $parentName => $childNames) {
            $parent = new Category();
            $parent->setName($parentName);
            $em->persist($parent);
            $this->categories[] = $parent;

            foreach ($childNames as $childName) {
                $child = new Category();
                $child->setName($childName);
                $child->setParent($parent);
                $em->persist($child);
                $this->categories[] = $child;
            }
        }

        // Create products
        $productData = [
            ['iPhone 15', 999.99, 50],
            ['MacBook Pro', 2499.99, 25],
            ['iPad Air', 599.99, 40],
            ['T-Shirt', 29.99, 200],
            ['Jeans', 79.99, 150],
            ['Dress', 89.99, 100],
            ['Novel Book', 14.99, 300],
            ['Programming Book', 49.99, 80],
            ['Biography', 19.99, 120],
        ];

        foreach ($productData as $index => [$name, $price, $stock]) {
            $product = new Product();
            $product->setName($name);
            $product->setPrice($price); // Using float (anti-pattern for testing)
            $product->setStock($stock);

            // Assign to a category (skip root categories)
            $categoryIndex = ($index % 9) + 3;
            if (isset($this->categories[$categoryIndex])) {
                $product->setCategory($this->categories[$categoryIndex]);
            }

            $em->persist($product);
            $this->products[] = $product;
        }
    }

    public function getLoadedEntities(): array
    {
        return array_merge($this->categories, $this->products);
    }

    /**
     * @return array<Category>
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /**
     * @return array<Product>
     */
    public function getProducts(): array
    {
        return $this->products;
    }
}
