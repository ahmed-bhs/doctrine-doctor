<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Pattern;

use PHPUnit\Framework\TestCase;

/**
 * Auto-generated tests for regex → str_contains() migration
 * Generated: 2025-11-13 07:55:15
 */
class SimpleKeywordDetectionTest extends TestCase
{

    /**
     * Test detection of 'ORDER BY' keyword
     */
    public function testOrderByDetection(): void
    {
        // Should match
        $this->assertTrue(
            str_contains(strtoupper('SELECT * FROM users ORDER BY name'), 'ORDER BY'),
            'Should detect ORDER BY in: SELECT * FROM users ORDER BY name'
        );

        $this->assertTrue(
            str_contains(strtoupper('select * from users order by name'), 'ORDER BY'),
            'Should detect ORDER BY in: select * from users order by name'
        );

        $this->assertTrue(
            str_contains(strtoupper('SELECT * FROM users WHERE id = 1 ORDER BY created_at DESC'), 'ORDER BY'),
            'Should detect ORDER BY in: SELECT * FROM users WHERE id = 1 ORDER BY created_at DESC'
        );

        // Should NOT match
        $this->assertFalse(
            str_contains(strtoupper('SELECT * FROM users'), 'ORDER BY'),
            'Should NOT detect ORDER BY in: SELECT * FROM users'
        );

        $this->assertFalse(
            str_contains(strtoupper('SELECT * FROM users GROUP BY status'), 'ORDER BY'),
            'Should NOT detect ORDER BY in: SELECT * FROM users GROUP BY status'
        );

    }

    /**
     * Test detection of 'GROUP BY' keyword
     */
    public function testGroupByDetection(): void
    {
        // Should match
        $this->assertTrue(
            str_contains(strtoupper('SELECT COUNT(*) FROM users GROUP BY status'), 'GROUP BY'),
            'Should detect GROUP BY in: SELECT COUNT(*) FROM users GROUP BY status'
        );

        $this->assertTrue(
            str_contains(strtoupper('select * from orders group by user_id'), 'GROUP BY'),
            'Should detect GROUP BY in: select * from orders group by user_id'
        );

        // Should NOT match
        $this->assertFalse(
            str_contains(strtoupper('SELECT * FROM users'), 'GROUP BY'),
            'Should NOT detect GROUP BY in: SELECT * FROM users'
        );

        $this->assertFalse(
            str_contains(strtoupper('SELECT * FROM users ORDER BY name'), 'GROUP BY'),
            'Should NOT detect GROUP BY in: SELECT * FROM users ORDER BY name'
        );

    }

    /**
     * Test detection of 'LEFT JOIN' keyword
     */
    public function testLeftJoinDetection(): void
    {
        // Should match
        $this->assertTrue(
            str_contains(strtoupper('SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id'), 'LEFT JOIN'),
            'Should detect LEFT JOIN in: SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id'
        );

        $this->assertTrue(
            str_contains(strtoupper('select * from users left join addresses on users.id = addresses.user_id'), 'LEFT JOIN'),
            'Should detect LEFT JOIN in: select * from users left join addresses on users.id = addresses.user_id'
        );

        // Should NOT match
        $this->assertFalse(
            str_contains(strtoupper('SELECT * FROM users'), 'LEFT JOIN'),
            'Should NOT detect LEFT JOIN in: SELECT * FROM users'
        );

        $this->assertFalse(
            str_contains(strtoupper('SELECT * FROM users INNER JOIN orders'), 'LEFT JOIN'),
            'Should NOT detect LEFT JOIN in: SELECT * FROM users INNER JOIN orders'
        );

    }

    /**
     * Test detection of 'DISTINCT' keyword
     */
    public function testDistinctDetection(): void
    {
        // Should match
        $this->assertTrue(
            str_contains(strtoupper('SELECT DISTINCT email FROM users'), 'DISTINCT'),
            'Should detect DISTINCT in: SELECT DISTINCT email FROM users'
        );

        $this->assertTrue(
            str_contains(strtoupper('select distinct status from orders'), 'DISTINCT'),
            'Should detect DISTINCT in: select distinct status from orders'
        );

        // Should NOT match
        $this->assertFalse(
            str_contains(strtoupper('SELECT * FROM users'), 'DISTINCT'),
            'Should NOT detect DISTINCT in: SELECT * FROM users'
        );

        $this->assertFalse(
            str_contains(strtoupper('SELECT email FROM users'), 'DISTINCT'),
            'Should NOT detect DISTINCT in: SELECT email FROM users'
        );

    }
}
