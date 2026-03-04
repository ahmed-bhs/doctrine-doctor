---
layout: default
title: Creating Analyzers
parent: Contributing
nav_order: 3
---

# Creating Custom Analyzers

Guide pratique pour ajouter un analyzer compatible avec l'API actuelle de Doctrine Doctor.

---

## 1. Contrat à respecter

Un analyzer doit:

- implémenter `AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface`
- exposer `analyze(QueryDataCollection $queryDataCollection): IssueCollection`
- rester stateless (pas d'état mutable partagé)
- être enregistré avec le tag `doctrine_doctor.analyzer`

Références:

- `src/Analyzer/AnalyzerInterface.php`
- `src/Collection/QueryDataCollection.php`
- `src/Collection/IssueCollection.php`

---

## 2. Exemple minimal

```php
<?php

declare(strict_types=1);

namespace App\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueCategory;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

final class LargeOffsetAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $offsetThreshold = 10000,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(function () use ($queryDataCollection) {
            foreach ($queryDataCollection as $queryData) {
                if (!str_contains(strtoupper($queryData->sql), ' OFFSET ')) {
                    continue;
                }

                if (!preg_match('/OFFSET\s+(\d+)/i', $queryData->sql, $matches)) {
                    continue;
                }

                $offset = (int) $matches[1];
                if ($offset < $this->offsetThreshold) {
                    continue;
                }

                $issueData = new IssueData(
                    type: IssueType::PERFORMANCE->value,
                    title: sprintf('Large OFFSET detected (%d)', $offset),
                    description: sprintf('Query uses OFFSET %d, which can be expensive on large datasets.', $offset),
                    severity: Severity::warning(),
                    category: IssueCategory::performance(),
                    suggestion: null,
                    queries: [$queryData->sql],
                    backtrace: $queryData->backtrace,
                    data: ['offset' => $offset, 'threshold' => $this->offsetThreshold],
                );

                yield $this->issueFactory->create($issueData);
            }
        });
    }
}
```

Notes:

- `QueryData` expose des propriétés (`$queryData->sql`, `$queryData->backtrace`, etc.)
- les sévérités valides sont `critical`, `warning`, `info`
- la catégorie métier correspond aux valeurs d'`IssueCategory` (`performance`, `security`, `integrity`, `configuration`)

---

## 3. Enregistrement du service

```yaml
# config/services.yaml
services:
    App\Analyzer\LargeOffsetAnalyzer:
        arguments:
            $offsetThreshold: 10000
        tags:
            - { name: 'doctrine_doctor.analyzer' }
```

---

## 4. Configuration utilisateur

Si vous exposez un seuil configurable, ajoutez une clé de config côté bundle puis documentez-la.

Exemple de consommation côté application:

```yaml
doctrine_doctor:
    analyzers:
        large_offset:
            enabled: true
            offset_threshold: 10000
```

---

## 5. Tests recommandés

Ajouter au minimum:

1. un test sans violation (aucun issue)
2. un test avec violation au-dessus du seuil
3. un test au seuil exact
4. un test de robustesse (requête inattendue/malformée)

---

## 6. Bonnes pratiques

- un analyzer = une responsabilité claire
- éviter les faux positifs bruyants
- produire des messages actionnables
- ajouter des données utiles dans `IssueData::data`
- garder la logique pure et facilement testable

---

## 7. Checklist PR

1. Analyzer implémenté
2. Service taggé `doctrine_doctor.analyzer`
3. Tests ajoutés
4. Documentation mise à jour (`docs/user-guide/analyzers.md` + exemples si utile)
5. Changelog mis à jour si nécessaire

---

**[← Development Setup](development)** | **[Configuration →]({{ site.baseurl }}/user-guide/configuration)**
