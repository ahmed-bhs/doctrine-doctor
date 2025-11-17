<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collection;

use Countable;
use IteratorAggregate;
use Webmozart\Assert\Assert;

/**
 * Base class for all strongly-typed collections.
 * Provides memory-efficient iteration using generators and common collection operations.
 * @template T
 * @implements IteratorAggregate<int, T>
 */
abstract class AbstractCollection implements IteratorAggregate, Countable
{
    /** @var array<int, T>|null Cached array (for generators) */
    private ?array $cachedArray = null;

    /** @var int|null Cached count */
    private ?int $count = null;

    /** @var bool Whether the generator has been consumed */
    private bool $generatorConsumed = false;

    /**
     *  Article pattern: Use iterable instead of array.
     * @param iterable<int, T> $items
     */
    protected function __construct(
        /**
         * @readonly
         */
        private iterable $items = [],
    ) {
        Assert::isIterable($items, 'Items must be iterable, got %s');
    }

    /**
     * Create collection from array (legacy compatibility).
     *  Prefer fromGenerator for better memory efficiency.
     * @param array<int, T> $items
     */
    public static function fromArray(array $items): static
    {
        // Type hint ensures $items is array - only validate it's a list (not associative)
        Assert::isList($items, 'Items must be a list (indexed array), got associative array');

        return static::createInstance($items);
    }

    /**
     *  Article pattern: Create collection from generator (RECOMMENDED)
     * Memory efficient for large datasets - loads on demand.
     * @param callable(): \Generator<int, T> $generator
     */
    public static function fromGenerator(callable $generator): static
    {
        // Type hint ensures $generator is callable
        $result = $generator();
        Assert::isInstanceOf($result, \Generator::class, 'Callable must return a Generator, got %s');

        return static::createInstance($result);
    }

    /**
     * Create empty collection.
     */
    public static function empty(): static
    {
        return static::createInstance([]);
    }

    /**
     *  Article pattern: Use 'yield from' for memory efficiency
     *  OPTIMIZED: Lazy iteration - yields items as they're produced
     *  Caches progressively to support multiple iterations.
     * @return \Generator<int, T>
     */
    public function getIterator(): \Generator
    {
        // If we have a cached array, use it to avoid generator reuse issues
        if (null !== $this->cachedArray) {
            yield from $this->cachedArray;

            return;
        }

        // If items is already an array, cache and use it
        if (is_array($this->items)) {
            $this->cachedArray = $this->items;
            yield from $this->cachedArray;

            return;
        }

        // OPTIMIZED: For generators, yield items lazily while caching progressively
        // This allows lazy evaluation while still supporting multiple iterations
        if ($this->generatorConsumed) {
            // Generator already consumed, use cached array
            if (null !== $this->cachedArray) {
                yield from $this->cachedArray;
            }

            return;
        }

        // Progressive caching: yield items as they come, cache for reuse
        $this->cachedArray = [];
        foreach ($this->items as $item) {
            $this->cachedArray[] = $item;
            yield $item;
        }
        $this->generatorConsumed = true;
    }

    /**
     * Convert collection to array.
     *  Cached: generators are converted once and reused.
     * @return array<int, T>
     */
    public function toArray(): array
    {
        // Return cached version if available
        if (null !== $this->cachedArray) {
            return $this->cachedArray;
        }

        // If items is already an array, use it directly
        if (is_array($this->items)) {
            $this->cachedArray = $this->items;

            return $this->cachedArray;
        }

        // OPTIMIZED: Use progressive caching via getIterator()
        // This ensures consistent caching behavior
        foreach ($this->getIterator() as $item) {
            // getIterator() handles caching, just consume it
        }

        return $this->cachedArray ?? [];
    }

    /**
     * Count elements.
     *  Realizes the entire collection - use sparingly.
     * @return int<0, max>
     */
    public function count(): int
    {
        if (null !== $this->count) {
            /** @var int<0, max> */
            return $this->count;
        }

        // For arrays, use count() directly
        if (is_array($this->items)) {
            $this->count = count($this->items);
            /** @var int<0, max> */
            return $this->count;
        }

        // For generators, need to iterate
        $this->count = iterator_count($this->getIterator());
        /** @var int<0, max> */
        return $this->count;
    }

    /**
     * Check if collection is empty.
     */
    public function isEmpty(): bool
    {
        return 0 === $this->count();
    }

    /**
     * Check if collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get first element or null.
     * @return T|null
     */
    public function first(): mixed
    {
        $items = $this->toArray();

        return $items[0] ?? null;
    }

    /**
     * Get last element or null.
     * @return T|null
     */
    public function last(): mixed
    {
        $items = $this->toArray();

        return [] === $items ? null : $items[array_key_last($items)];
    }

    /**
     *  Article pattern: Filter returns generator, not array.
     * @param callable(T): bool $predicate
     */
    public function filter(callable $predicate): static
    {
        return static::fromGenerator(function () use ($predicate) {
            foreach ($this->getIterator() as $item) {
                if ($predicate($item)) {
                    yield $item;
                }
            }
        });
    }

    /**
     * Map collection to new values.
     * @template U
     * @param callable(T): U $mapper
     * @return array<int, U>
     */
    public function map(callable $mapper): array
    {
        $result = [];

        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $item) {
            $result[] = $mapper($item);
        }

        return $result;
    }

    /**
     * Check if any element matches predicate.
     * @param callable(T): bool $predicate
     */
    public function any(callable $predicate): bool
    {
        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all elements match predicate.
     * @param callable(T): bool $predicate
     */
    public function all(callable $predicate): bool
    {
        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $item) {
            if (!$predicate($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Group items by key returned by callback.
     * @template K of array-key
     * @param callable(T): K $keySelector
     * @return array<K, static>
     */
    public function groupBy(callable $keySelector): array
    {
        $groups = [];

        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $item) {
            $key = $keySelector($item);

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $item;
        }

        return array_map(function ($group) {
            return static::fromArray($group);
        }, $groups);
    }

    /**
     * Create a new instance of the concrete collection class.
     * Each concrete collection must implement this to avoid unsafe new static() in abstract class.
     * @param iterable<int, T> $items
     */
    abstract protected static function createInstance(iterable $items): static;
}
