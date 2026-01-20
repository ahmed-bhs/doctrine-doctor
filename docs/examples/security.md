---
layout: default
title: Security Issues
parent: Examples
nav_order: 3
---

# Security Issue Detection Examples

Learn how Doctrine Doctor identifies security vulnerabilities in Doctrine ORM usage.

---

## Security Analyzers Overview

Doctrine Doctor includes several security-focused analyzers:

- **DQL Injection** - Detects unsafe query construction
- **SQL Injection** - Identifies raw SQL vulnerabilities
- **Sensitive Data Exposure** - Finds unprotected sensitive fields
- **Insecure Random** - Detects weak random number generation

---

## Example 1: DQL Injection

### Problem Code

```php
<?php
// src/Repository/UserRepository.php

public function findByUsername(string $username): ?User
{
    // DANGEROUS: Direct string concatenation
    $dql = "SELECT u FROM App\Entity\User u WHERE u.username = '" . $username . "'";

    return $this->getEntityManager()
        ->createQuery($dql)
        ->getOneOrNullResult();
}
```

### Exploitation

```php
<?php
// Attacker input
$username = "' OR '1'='1";

// Resulting DQL
"SELECT u FROM App\Entity\User u WHERE u.username = '' OR '1'='1'"
// Returns all users!
```

### Doctrine Doctor Detection

```text
🔴 Critical: DQL Injection Vulnerability

Query construction uses string concatenation with user input.

Query: SELECT u FROM App\Entity\User u WHERE u.username = '...'

Risk: Attackers can modify query logic to access unauthorized data.

Backtrace:
- src/Repository/UserRepository.php:15
- src/Controller/UserController.php:42
```

### Solution: Use Parameter Binding

```php
<?php

public function findByUsername(string $username): ?User
{
    $dql = "SELECT u FROM App\Entity\User u WHERE u.username = :username";

    return $this->getEntityManager()
        ->createQuery($dql)
        ->setParameter('username', $username)  // Safe parameterized query
        ->getOneOrNullResult();
}
```

Or use QueryBuilder (recommended):

```php
<?php

public function findByUsername(string $username): ?User
{
    return $this->createQueryBuilder('u')
        ->where('u.username = :username')
        ->setParameter('username', $username)
        ->getQuery()
        ->getOneOrNullResult();
}
```

---

## Example 2: SQL Injection in Native Queries

### Problem Code

```php
<?php
// src/Repository/ProductRepository.php

public function searchProducts(string $category, string $sortBy): array
{
    $sql = "SELECT * FROM product
            WHERE category = '$category'
            ORDER BY $sortBy";  // DANGEROUS!

    $conn = $this->getEntityManager()->getConnection();
    return $conn->executeQuery($sql)->fetchAllAssociative();
}
```

### Exploitation

```php
<?php
// Attacker input
$category = "electronics' OR '1'='1";
$sortBy = "price; DROP TABLE product--";

// Potential SQL injection and data destruction
```

### Doctrine Doctor Detection

```text
🔴 Critical: SQL Injection Vulnerability

Native SQL query uses string concatenation with unescaped input.

Query: SELECT * FROM product WHERE category = '...' ORDER BY ...

Risk: Direct SQL injection. Attacker can execute arbitrary SQL.

Recommendation: Use prepared statements with parameter binding.
```

### Solution: Parameterized Queries

```php
<?php

public function searchProducts(string $category, string $sortBy): array
{
    // Whitelist allowed sort columns
    $allowedSortColumns = ['name', 'price', 'created_at'];
    if (!in_array($sortBy, $allowedSortColumns, true)) {
        throw new \InvalidArgumentException('Invalid sort column');
    }

    $sql = "SELECT * FROM product
            WHERE category = :category
            ORDER BY " . $sortBy;  // Safe: validated against whitelist

    $conn = $this->getEntityManager()->getConnection();
    return $conn->executeQuery($sql, ['category' => $category])
        ->fetchAllAssociative();
}
```

Better solution with QueryBuilder:

```php
<?php

public function searchProducts(string $category, string $sortBy): array
{
    $allowedSortColumns = ['name', 'price', 'createdAt'];
    if (!in_array($sortBy, $allowedSortColumns, true)) {
        throw new \InvalidArgumentException('Invalid sort column');
    }

    return $this->createQueryBuilder('p')
        ->where('p.category = :category')
        ->orderBy('p.' . $sortBy, 'ASC')
        ->setParameter('category', $category)
        ->getQuery()
        ->getResult();
}
```

---

## Example 3: Sensitive Data Exposure

### Problem Code

```php
<?php
// src/Entity/User.php

#[ORM\Entity]
class User implements \JsonSerializable
{
    #[ORM\Column(type: 'string')]
    private string $password;  // Hashed

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $ssn = null;  // Social Security Number

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $creditCard = null;

    // DANGEROUS: Exposes all fields
    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }
}
```

### Doctrine Doctor Detection

```text
🔴 Critical: Sensitive Data Exposure

Entity User implements JsonSerializable and exposes all properties.

Sensitive fields detected:
- password (even if hashed, shouldn't be exposed)
- ssn
- creditCard

Risk: Accidental exposure in API responses, logs, or serialization.

Recommendation: Use explicit property whitelisting.
```

### Solution: Whitelist Exposed Fields

```php
<?php

#[ORM\Entity]
class User implements \JsonSerializable
{
    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $ssn = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $creditCard = null;

    #[ORM\Column(type: 'string')]
    private string $email;

    #[ORM\Column(type: 'string')]
    private string $username;

    // Safe: Only expose non-sensitive fields
    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            // Sensitive fields NOT included
        ];
    }
}
```

Better: Use Symfony Serializer with Groups:

```php
<?php

use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
class User
{
    #[ORM\Column(type: 'string')]
    #[Groups(['user:read'])]
    private string $username;

    #[ORM\Column(type: 'string')]
    private string $password;  // No group = not serialized by default

    #[ORM\Column(type: 'string')]
    private ?string $ssn = null;  // No group = not serialized
}
```

---

## Example 4: Insecure Random Token Generation

### Problem Code

```php
<?php
// src/Service/TokenGenerator.php

class TokenGenerator
{
    public function generateResetToken(): string
    {
        // INSECURE: Predictable randomness
        return md5(uniqid(mt_rand(), true));
    }

    public function generateApiKey(): string
    {
        // INSECURE: time() is predictable
        return hash('sha256', time() . rand());
    }
}
```

### Doctrine Doctor Detection

```text
🔴 Critical: Insecure Random Number Generation

Detected use of weak random functions: mt_rand(), rand(), uniqid()

Context: Token generation for security purposes

Risk: Attackers can predict tokens and gain unauthorized access.

Recommendation: Use random_bytes() or random_int() for security tokens.
```

### Solution: Cryptographically Secure Random

```php
<?php

class TokenGenerator
{
    public function generateResetToken(): string
    {
        // Secure: Cryptographically strong randomness
        return bin2hex(random_bytes(32));
    }

    public function generateApiKey(): string
    {
        // Secure: Using Symfony's random_bytes wrapper
        return base64_encode(random_bytes(32));
    }
}
```

---

## Example 5: Mass Assignment Vulnerability

### Problem Code

```php
<?php
// src/Controller/UserController.php

#[Route('/api/users/{id}', methods: ['PUT'])]
public function update(Request $request, User $user, EntityManagerInterface $em): Response
{
    $data = json_decode($request->getContent(), true);

    // DANGEROUS: Blindly updating all fields
    foreach ($data as $key => $value) {
        $setter = 'set' . ucfirst($key);
        if (method_exists($user, $setter)) {
            $user->$setter($value);
        }
    }

    $em->flush();

    return $this->json($user);
}
```

### Attack Scenario

```bash
# Attacker sends malicious payload
curl -X PUT /api/users/123 \
  -H "Content-Type: application/json" \
  -d '{
    "username": "newname",
    "isAdmin": true,      # Privilege escalation!
    "credits": 999999      # Unauthorized balance modification
  }'
```

### Doctrine Doctor Detection

```text
🟠 High: Potential Mass Assignment Vulnerability

Controller directly maps request data to entity properties without validation.

Risk: Attackers can modify unintended fields (roles, permissions, balances).

Recommendation: Use explicit DTO mapping or Form component.
```

### Solution: Use DTOs and Validation

```php
<?php

// src/Dto/UpdateUserDto.php
class UpdateUserDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    public string $username;

    #[Assert\Email]
    public string $email;

    // Only allowed fields defined
}
```

```php
<?php
// src/Controller/UserController.php

#[Route('/api/users/{id}', methods: ['PUT'])]
public function update(
    Request $request,
    User $user,
    ValidatorInterface $validator,
    SerializerInterface $serializer,
    EntityManagerInterface $em
): Response {
    // Deserialize to DTO
    $dto = $serializer->deserialize(
        $request->getContent(),
        UpdateUserDto::class,
        'json'
    );

    // Validate
    $errors = $validator->validate($dto);
    if (count($errors) > 0) {
        return $this->json($errors, 400);
    }

    // Explicit mapping - only allowed fields
    $user->setUsername($dto->username);
    $user->setEmail($dto->email);
    // isAdmin, credits, etc. NOT modifiable

    $em->flush();

    return $this->json($user);
}
```

---

## Security Configuration

All security analyzers should always be enabled:

```yaml
doctrine_doctor:
    analyzers:
        dql_injection:
            enabled: true  # Always on
        sql_injection:
            enabled: true  # Always on
        sensitive_data_exposure:
            enabled: true  # Always on
        insecure_random:
            enabled: true  # Always on
```

---

## Security Best Practices

### 1. Never Trust User Input

```php
// Bad
$dql = "SELECT u FROM User u WHERE u.role = '{$_GET['role']}'";

// Good
$qb->where('u.role = :role')->setParameter('role', $_GET['role']);
```

### 2. Use Parameterized Queries

```php
// Bad
$em->createQuery("SELECT u FROM User u WHERE u.id = " . $id);

// Good
$em->createQuery("SELECT u FROM User u WHERE u.id = :id")
   ->setParameter('id', $id);
```

### 3. Whitelist, Don't Blacklist

```php
// Bad: Trying to block dangerous input
if (str_contains($input, 'DROP') || str_contains($input, 'DELETE')) {
    throw new Exception('Invalid input');
}

// Good: Only allow known-safe values
$allowedColumns = ['name', 'email', 'created_at'];
if (!in_array($column, $allowedColumns, true)) {
    throw new InvalidArgumentException();
}
```

### 4. Use Framework Security Features

```php
// Symfony Security for authorization
#[IsGranted('ROLE_ADMIN')]
public function adminAction() { }

// Symfony Validator for input validation
#[Assert\Email]
private string $email;
```

---

## Related Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Symfony Security Best Practices](https://symfony.com/doc/current/security.html)
- [Doctrine Security](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/security.html)

---

**[← Missing Indexes](missing-indexes)** | **[Contributing →]({{ site.baseurl }}/contributing/overview)**
