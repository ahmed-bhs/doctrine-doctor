<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

class DQLInjectionSuggestion implements SuggestionInterface
{
    private readonly string $riskLevel;

    private readonly array $indicators;

    private readonly string $example;

    public function __construct(array $data)
    {
        $this->riskLevel  = $data['risk_level'] ?? 'unknown';
        $this->indicators = $data['indicators'] ?? [];
        $this->example    = $this->generateExample();
    }

    public function getCode(): string
    {
        return $this->example;
    }

    public function getDescription(): string
    {
        return sprintf(
            'CRITICAL SECURITY ISSUE: %s risk SQL injection detected. ' .
            'Use parameterized queries with setParameter() to prevent SQL injection attacks.',
            ucfirst($this->riskLevel),
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::SECURITY,
            severity: Severity::CRITICAL,
            title: 'DQL Injection Prevention',
            tags: ['security', 'sql-injection'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'      => static::class,
            'risk_level' => $this->riskLevel,
            'indicators' => $this->indicators,
            'example'    => $this->example,
        ];
    }

    private function generateExample(): string
    {
        return <<<'CODE'
            //  DANGER CRITICAL: SQL Injection!
            $name = $_GET['name']; // User input
            $dql = "SELECT u FROM User u WHERE u.name = '" . $name . "'";
            $query = $em->createQuery($dql);
            $users = $query->getResult();

            //  Possible attack:
            // URL: ?name=admin' OR '1'='1
            // Query: SELECT u FROM User u WHERE u.name = 'admin' OR '1'='1'
            // Result: ALL users returned!

            //  SOLUTION 1: Named parameters (recommended)
            $name = $_GET['name'];
            $dql = "SELECT u FROM User u WHERE u.name = :name";
            $query = $em->createQuery($dql);
            $query->setParameter('name', $name); // ✓ Secured!
            $users = $query->getResult();

            //  SOLUTION 2: Positional parameters
            $dql = "SELECT u FROM User u WHERE u.name = ?1";
            $query = $em->createQuery($dql);
            $query->setParameter(1, $name);
            $users = $query->getResult();

            //  SOLUTION 3: Query Builder (safest)
            $users = $repository->createQueryBuilder('u')
                ->where('u.name = :name')
                ->setParameter('name', $name)
                ->getQuery()
                ->getResult();

            //  SOLUTION 4: Criteria with findBy
            $users = $repository->findBy(['name' => $name]);

            //  GOLDEN RULES:
            // 1. NEVER direct concatenation
            // 2. ALWAYS use setParameter()
            // 3. NEVER trust user input
            // 4. Validate AND escape data

            //  Other dangerous cases to avoid:

            // Cas 1: Concatenation in LIKE
            $search = $_GET['search'];
            //  Secured version:
            $query->setParameter('search', '%' . $search . '%');

            // Cas 2: Concatenation in IN
            $ids = $_GET['ids']; // "1,2,3"
            //  Secured version:
            $dql = "SELECT u FROM User u WHERE u.id IN (:ids)";
            $query->setParameter('ids', $idsArray);

            // Cas 3: Dynamic ORDER BY
            $sort = $_GET['sort']; // "name" ou "name; DROP TABLE users--"
            //  Secured version: Whitelist
            $allowedFields = ['name', 'email', 'createdAt'];
            $sort = in_array($sort, $allowedFields) ? $sort : 'name';
            $dql = "SELECT u FROM User u ORDER BY u." . $sort;

            //  DEFENSE IN DEPTH:

            // 1. Input validation
            if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
                throw new InvalidArgumentException('Invalid username format');
            }

            // 2. Length limitation
            if (strlen($input) > 100) {
                throw new InvalidArgumentException('Input too long');
            }

            // 3. Type casting
            $id = (int) $_GET['id']; // Force integer

            // 4. Use enums for fixed values
            enum UserRole: string {
                case ADMIN = 'admin';
                case USER = 'user';
            }
            $role = UserRole::from($_GET['role']); // Exception if invalid

            //  IMPORTANT:
            // - setParameter() protects against SQL injection
            // - But does NOT protect against XSS, CSRF, etc.
            // - Always validate and sanitize inputs
            // - Apply the principle of least privilege
            CODE;
    }
}
