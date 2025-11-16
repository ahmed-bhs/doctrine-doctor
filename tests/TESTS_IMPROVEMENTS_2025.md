# Tests pour les Améliorations 2025

## 📊 Couverture des Tests

### Tests Créés

#### 1. TraitCollectionInitializationDetectorTest
**Fichier**: `tests/Unit/Analyzer/Helper/TraitCollectionInitializationDetectorTest.php`

**Cas testés** :
- ✅ Détection d'initialisation directe dans un trait
- ✅ Détection du pattern Sylius (constructor aliasing)
- ✅ Détection dans des traits imbriqués (traits utilisant d'autres traits)
- ✅ Retour false quand non initialisé
- ✅ Retour false pour champ inexistant
- ✅ Ignore les commentaires dans le code

**Fixtures incluses** :
- `TraitWithDirectInitialization`: Trait basique
- `TranslatableTrait` + `SyliusStyleClass`: Pattern Sylius
- `BaseCollectionTrait` + `MiddleTrait`: Traits imbriqués

#### 2. CompositionRelationshipDetectorTest
**Fichier**: `tests/Unit/Analyzer/Helper/CompositionRelationshipDetectorTest.php`

**Cas testés** :
- ✅ OneToOne avec orphanRemoval → Composition
- ✅ OneToOne avec cascade remove → Composition
- ✅ OneToOne sans indicateurs → PAS composition
- ✅ OneToMany avec orphanRemoval → Composition
- ✅ OneToMany avec nom suggérant composition (OrderItem, etc.)
- ✅ OneToMany sans cascade remove → PAS composition
- ✅ ManyToOne avec FK unique → Composition 1:1
- ✅ Data provider pour patterns de noms

**Heuristiques validées** :
1. orphanRemoval detection
2. cascade remove detection
3. Unique constraint detection
4. Child name patterns (Item, Line, Entry, etc.)
5. Independent entity patterns (User, Customer, etc.)

#### 3. PhpCodeParserTest
**Fichier**: `tests/Unit/Analyzer/Parser/PhpCodeParserTest.php`

**Cas testés** :
- ✅ Détection `new ArrayCollection()`
- ✅ Détection `[]`
- ✅ Détection FQN `\Doctrine\Common\Collections\ArrayCollection`
- ✅ Détection d'appels de méthode `initializeItemsCollection()`
- ✅ Support wildcards `initialize*Collection`
- ✅ Ignore commentaires automatiquement
- ✅ Ignore strings automatiquement
- ✅ Ignore autres champs
- ✅ Gère espacements variés
- ✅ Gère assignations multilignes
- ✅ Test du cache AST
- ✅ Test clearCache()

**Fixtures incluses** :
- `TestEntity` avec 11 méthodes de test

#### 4. CollectionInitializationVisitorTest
**Fichier**: `tests/Unit/Analyzer/Parser/Visitor/CollectionInitializationVisitorTest.php`

**Cas testés** :
- ✅ Détection simple `new ArrayCollection()`
- ✅ Détection FQN
- ✅ Détection `[]` vide
- ✅ Ignore `[1, 2, 3]` (non vide)
- ✅ Spécificité du champ (ne détecte que le bon)
- ✅ Ignore commentaires (automatique dans AST)
- ✅ Ignore strings
- ✅ Ignore propriétés statiques `self::$items`
- ✅ Ignore variables locales `$items`
- ✅ Gère instructions multiples
- ✅ Gère scopes imbriqués (if, foreach, etc.)

#### 5. MethodCallVisitorTest
**Fichier**: `tests/Unit/Analyzer/Parser/Visitor/MethodCallVisitorTest.php`

**Cas testés** :
- ✅ Détection exacte de méthode `$this->initializeItemsCollection()`
- ✅ Détection avec wildcard prefix `initialize*`
- ✅ Détection avec wildcard suffix `*Collection`
- ✅ Détection avec wildcard milieu `init*Collection`
- ✅ Data provider pour divers patterns wildcards
- ✅ Ignore autres méthodes
- ✅ Ignore appels statiques `self::method()`
- ✅ Ignore fonctions (non méthodes)
- ✅ Ignore autres objets `$obj->method()`
- ✅ Ignore commentaires
- ✅ Ignore strings
- ✅ Détection dans scopes imbriqués
- ✅ Pattern Sylius avec constructor aliasing
- ✅ Cas limites (nombres, underscores, sensibilité casse)

---

## 🚀 Lancer les Tests

### Tous les nouveaux tests
```bash
cd /home/ahmed/Projets/doctrine-doctor

# Option 1: Tous les tests unitaires
vendor/bin/phpunit tests/Unit/

# Option 2: Tests spécifiques
vendor/bin/phpunit tests/Unit/Analyzer/Helper/
vendor/bin/phpunit tests/Unit/Analyzer/Parser/

# Option 3: Test spécifique
vendor/bin/phpunit tests/Unit/Analyzer/Helper/TraitCollectionInitializationDetectorTest.php
```

### Avec couverture de code
```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html coverage/ tests/Unit/
```

### Tests rapides (sans couverture)
```bash
vendor/bin/phpunit --no-coverage tests/Unit/
```

---

## 📋 Checklist de Validation

### Tests Unitaires
- [x] TraitCollectionInitializationDetector
- [x] CompositionRelationshipDetector
- [x] PhpCodeParser
- [x] CollectionInitializationVisitor
- [x] MethodCallVisitor

### Tests d'Intégration
- [ ] CollectionInitializationAnalyzer avec nouveau détecteur
- [ ] CascadeRemoveOnIndependentEntityAnalyzer avec nouveau détecteur
- [ ] BidirectionalConsistencyAnalyzer avec fix nullable
- [ ] Tests end-to-end sur projet Sylius

### Tests de Régression
- [ ] Vérifier qu'aucun test existant n'est cassé
- [ ] Vérifier que les faux positifs ont disparu

---

## 🎯 Résultats Attendus

### Avant Améliorations
```
Tests: 150 passed
False Positives: 16 issues détectées (59%)
Coverage: 70%
```

### Après Améliorations (Cible)
```
Tests: 200+ passed
False Positives: ~0 issues (0%)
Coverage: 85%+
```

---

## 🔍 Tests Supplémentaires Recommandés

### 1. Tests d'Intégration
```php
namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

final class CollectionInitializationAnalyzerIntegrationTest extends TestCase
{
    public function testDetectsSyliusPattern(): void {
        // Test avec entités Sylius réelles
    }
}
```

### 2. Tests de Performance
```php
final class PhpCodeParserPerformanceTest extends TestCase
{
    public function testCachingImproves Performance(): void {
        // Benchmark avec/sans cache
    }
}
```

---

## 📊 Métriques de Qualité

### Complexité Cyclomatique
```
Avant:
- TraitCollectionInitializationDetector: 18
- CascadeRemoveOnIndependentEntityAnalyzer: 25

Après:
- TraitCollectionInitializationDetector: 6 (-67%)
- CompositionRelationshipDetector: 8
- PhpCodeParser: 4
```

### Lignes de Code
```
Avant:
- Regex-based detection: 240 lignes

Après:
- Parser-based detection: 80 lignes (-67%)
- Tests: 400+ lignes (+∞)
```

### Couverture
```
Avant: 70%
Après: 85%+ (cible)
```

---

## 🛠️ Comment Ajouter un Nouveau Test

### 1. Créer le fichier de test
```bash
touch tests/Unit/Analyzer/YourAnalyzerTest.php
```

### 2. Structure de base
```php
<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer;

use PHPUnit\Framework\TestCase;

final class YourAnalyzerTest extends TestCase
{
    private YourAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new YourAnalyzer();
    }

    public function testYourScenario(): void
    {
        // Given: Setup
        // When: Action
        // Then: Assert
        $this->assertTrue(true);
    }
}
```

### 3. Lancer le test
```bash
vendor/bin/phpunit tests/Unit/Analyzer/YourAnalyzerTest.php
```

---

## 🐛 Debug des Tests

### Test qui échoue
```bash
# Verbose mode
vendor/bin/phpunit --testdox tests/Unit/YourTest.php

# Avec stack trace complète
vendor/bin/phpunit --testdox --stop-on-failure tests/Unit/YourTest.php
```

### Vérifier la couverture d'une classe
```bash
XDEBUG_MODE=coverage vendor/bin/phpunit \
  --coverage-filter src/Analyzer/Helper/TraitCollectionInitializationDetector.php \
  --coverage-text \
  tests/Unit/Analyzer/Helper/TraitCollectionInitializationDetectorTest.php
```

---

## ✅ Critères d'Acceptation

Un test est considéré comme complet quand :
- ✅ Il teste le happy path
- ✅ Il teste les cas d'erreur
- ✅ Il teste les edge cases
- ✅ Il a des noms descriptifs (Given/When/Then)
- ✅ Il est isolé (pas de dépendances externes)
- ✅ Il est rapide (<100ms)
- ✅ Il est déterministe (toujours le même résultat)

---

## 🎓 Ressources

### Documentation PHPUnit
- [PHPUnit Assertions](https://phpunit.readthedocs.io/en/10.5/assertions.html)
- [PHPUnit Annotations](https://phpunit.readthedocs.io/en/10.5/annotations.html)
- [PHPUnit Data Providers](https://phpunit.readthedocs.io/en/10.5/writing-tests-for-phpunit.html#data-providers)

### Documentation nikic/php-parser
- [PHP-Parser Documentation](https://github.com/nikic/PHP-Parser/tree/master/doc)
- [AST Explorer](https://php-ast-explorer.com/)

---

## 📝 Notes

### Conventions
- Nommer les tests en anglais
- Utiliser Given/When/Then pattern
- Un test = un concept
- Fixtures à la fin du fichier

### Performance
- Utiliser `@dataProvider` pour tests paramétrés
- Créer les mocks dans `setUp()` si réutilisés
- Éviter les sleeps et I/O

### Best Practices
- Tests atomiques et indépendants
- Pas de logique complexe dans les tests
- Assertions claires avec messages explicites
- Fixtures réalistes mais minimales
