---
layout: default
title: Quick Start
parent: Getting Started
nav_order: 1
---

# Quick Start

Get Doctrine Doctor running in your Symfony project in under 30 seconds.

---

## Installation

Install via Composer:

```bash
composer require --dev ahmed-bhs/doctrine-doctor
```

{: .note-title }
> Auto-Configuration
>
> Doctrine Doctor is automatically configured via [Symfony Flex](https://github.com/symfony/recipes-contrib/pull/1882). No manual setup required!

---

## Verify Installation

1. **Refresh any page** in your Symfony application (in `dev` environment)
2. **Open the Symfony Web Profiler** (bottom toolbar)
3. **Look for the "Doctrine Doctor" panel**

---

## First Analysis

Doctrine Doctor automatically analyzes all database queries executed during your request.

### Example: Detect N+1 Query

Create a simple controller:

```php
<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
    #[Route('/users', name: 'app_users')]
    public function index(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAll();

        return $this->render('user/index.html.twig', [
            'users' => $users,
        ]);
    }
}
```

And a Twig template:

```twig
{% raw %}{# templates/user/index.html.twig #}
{% for user in users %}
    <p>{{ user.name }} - {{ user.profile.bio }}</p>
{% endfor %}{% endraw %}
```

**Result**: Doctrine Doctor will detect the N+1 query issue and suggest using eager loading!

---

## Enable Backtraces (Recommended)

To see exactly WHERE in your code issues originate, enable query backtraces:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

{: .highlight }
> **Performance Impact**: Backtrace collection has minimal overhead (~2-5%) and is recommended for development.

---

## Next Steps

- [Configure analyzers]({{ site.baseurl }}/user-guide/configuration) to match your needs
- [Explore all 66 analyzers]({{ site.baseurl }}/user-guide/analyzers) available
- [Learn about the architecture]({{ site.baseurl }}/advanced/architecture)
- [See more examples]({{ site.baseurl }}/examples/n-plus-one)
