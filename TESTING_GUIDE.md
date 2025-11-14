# Guide de Tests - Doctrine Doctor

## 🎯 Objectif

Ce guide explique comment tester les améliorations apportées à Doctrine Doctor, notamment :
- Détection d'initialisation de collections via traits
- Détection de relations de composition vs agrégation
- Utilisation de PHP Parser au lieu de regex

---

## 🚀 Démarrage Rapide

### Lancer tous les nouveaux tests

```bash
cd /home/ahmed/Projets/doctrine-doctor

# Option 1: Script automatique
./run-new-tests.sh

# Option 2: Avec couverture de code
./run-new-tests.sh --coverage

# Option 3: PHPUnit direct
vendor/bin/phpunit tests/Unit/Analyzer/Helper/
vendor/bin/phpunit tests/Unit/Analyzer/Parser/
```

---

## 📦 Installation des Dépendances

### nikic/php-parser (requis pour les nouveaux tests)

```bash
# Installer la dépendance
composer require nikic/php-parser

# Ou mettre à jour toutes les dépendances
composer update
```

### Xdebug (optionnel, pour la couverture)

```bash
# Ubuntu/Debian
sudo apt-get install php-xdebug

# Vérifier l'installation
php -v | grep Xdebug
```

---

## 🧪 Tests par Composant

### 1. TraitCollectionInitializationDetector

**Teste** : Détection d'initialisation de collections dans les traits

```bash
vendor/bin/phpunit tests/Unit/Analyzer/Helper/TraitCollectionInitializationDetectorTest.php --testdox
```

**Scénarios testés** :
- ✅ Initialisation directe : `$this->items = new ArrayCollection()`
- ✅ Pattern Sylius avec constructor aliasing
- ✅ Traits imbriqués (traits utilisant d'autres traits)
- ✅ Retour false quand non initialisé
- ✅ Ignore les commentaires

**Sortie attendue** :
```
✔ Detects direct collection initialization in trait
✔ Detects sylius style constructor aliasing
✔ Detects nested traits
✔ Returns false when not initialized
✔ Returns false for non existent field
✔ Ignores comments in trait code
```

---

### 2. CompositionRelationshipDetector

**Teste** : Détection de relations de composition vs agrégation

```bash
vendor/bin/phpunit tests/Unit/Analyzer/Helper/CompositionRelationshipDetectorTest.php --testdox
```

**Scénarios testés** :
- ✅ OneToOne avec orphanRemoval
- ✅ OneToOne avec cascade remove
- ✅ OneToMany par nom d'entité (OrderItem, etc.)
- ✅ ManyToOne avec FK unique (1:1 déguisé)
- ✅ Data provider pour patterns de noms

**Sortie attendue** :
```
✔ Detects one to one composition with orphan removal
✔ Detects one to one composition with cascade remove
✔ Rejects one to one without composition indicators
✔ Detects one to many composition with orphan removal
✔ Detects one to many composition by child name
...
```

---

### 3. PhpCodeParser

**Teste** : Parser PHP remplaçant les regex

```bash
vendor/bin/phpunit tests/Unit/Analyzer/Parser/PhpCodeParserTest.php --testdox
```

**Scénarios testés** :
- ✅ Détection `new ArrayCollection()`
- ✅ Détection `[]`
- ✅ FQN : `\Doctrine\Common\Collections\ArrayCollection`
- ✅ Appels de méthodes avec wildcards
- ✅ Ignore automatiquement commentaires et strings
- ✅ Cache AST pour performance

**Sortie attendue** :
```
✔ Detects array collection initialization
✔ Detects array initialization
✔ Detects FQN array collection
✔ Detects initialization method call
✔ Ignores commented initialization
✔ Ignores string literals
✔ Caches AST
...
```

---

### 4. CollectionInitializationVisitor

**Teste** : Visitor Pattern pour l'AST

```bash
vendor/bin/phpunit tests/Unit/Analyzer/Parser/Visitor/CollectionInitializationVisitorTest.php --testdox
```

**Scénarios testés** :
- ✅ Détection dans l'AST
- ✅ Spécificité du champ
- ✅ Ignore commentaires (automatique)
- ✅ Ignore propriétés statiques
- ✅ Gère scopes imbriqués

---

## 📊 Couverture de Code

### Générer un rapport de couverture

```bash
# Couverture HTML (recommandé)
XDEBUG_MODE=coverage vendor/bin/phpunit \
    tests/Unit/Analyzer/Helper/ \
    tests/Unit/Analyzer/Parser/ \
    --coverage-html=coverage/improvements-2025

# Ouvrir dans le navigateur
xdg-open coverage/improvements-2025/index.html

# Couverture en ligne de commande
XDEBUG_MODE=coverage vendor/bin/phpunit \
    tests/Unit/ \
    --coverage-text
```

### Couverture par classe spécifique

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit \
    --coverage-filter src/Analyzer/Helper/TraitCollectionInitializationDetector.php \
    --coverage-text \
    tests/Unit/Analyzer/Helper/TraitCollectionInitializationDetectorTest.php
```

---

## 🐛 Debugging des Tests

### Mode Verbose

```bash
# Afficher les détails
vendor/bin/phpunit --testdox --verbose tests/Unit/

# Arrêter au premier échec
vendor/bin/phpunit --stop-on-failure tests/Unit/

# Debug d'un test spécifique
vendor/bin/phpunit --filter testDetectsSyliusStyleConstructorAliasing tests/Unit/
```

### Afficher les erreurs détaillées

```bash
# Stack trace complète
vendor/bin/phpunit --testdox --verbose --debug tests/Unit/

# Avec var_dump
# Ajouter dans le test: var_dump($variable);
vendor/bin/phpunit --colors=always tests/Unit/
```

---

## 📝 Écrire un Nouveau Test

### Template de base

```php
<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer;

use PHPUnit\Framework\TestCase;

final class MyNewAnalyzerTest extends TestCase
{
    private MyNewAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new MyNewAnalyzer();
    }

    public function testMyScenario(): void
    {
        // Given: Préparation
        $input = 'some input';

        // When: Action
        $result = $this->analyzer->analyze($input);

        // Then: Vérification
        $this->assertTrue($result, 'Expected result to be true');
    }
}
```

### Bonnes pratiques

1. **Noms descriptifs** : `testDetectsSyliusStyleConstructorAliasing`
2. **Structure Given/When/Then** : Clarté
3. **Un test = un concept** : Atomicité
4. **Messages d'assertion** : Facilite le debug
5. **Fixtures minimales** : Juste ce qu'il faut

---

## 🎯 Validation des Améliorations

### Checklist de validation

#### Tests Unitaires ✅
- [x] TraitCollectionInitializationDetector
- [x] CompositionRelationshipDetector
- [x] PhpCodeParser
- [x] CollectionInitializationVisitor
- [ ] MethodCallVisitor (TODO)

#### Tests d'Intégration 🔄
- [ ] Analyser projet Sylius
- [ ] Vérifier faux positifs éliminés
- [ ] Comparer avant/après

#### Tests de Régression ⏳
- [ ] Tests existants toujours valides
- [ ] Pas de breaking changes

---

## 🚨 Résolution de Problèmes

### "Class not found"

```bash
# Régénérer l'autoload
composer dump-autoload
```

### "nikic/php-parser not installed"

```bash
# Installer la dépendance
composer require nikic/php-parser
```

### Tests échouent avec "Cannot modify header information"

```bash
# Désactiver output buffering
php -d output_buffering=4096 vendor/bin/phpunit tests/Unit/
```

### Xdebug ralentit les tests

```bash
# Désactiver Xdebug pour tests rapides
php -d xdebug.mode=off vendor/bin/phpunit tests/Unit/
```

---

## 📈 Métriques de Succès

### Avant Améliorations
```
Tests: 150
Temps: ~30s
Couverture: 70%
Faux Positifs: 16 (59%)
```

### Après Améliorations (Cible)
```
Tests: 200+
Temps: ~35s (+16%)
Couverture: 85%+ (+15%)
Faux Positifs: 0 (0%) (-100% 🎉)
```

---

## 🎓 Ressources

### Documentation
- [PHPUnit Documentation](https://phpunit.readthedocs.io/)
- [PHP-Parser GitHub](https://github.com/nikic/PHP-Parser)
- [Doctrine ORM Testing](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/testing.html)

### Outils
- [AST Explorer](https://php-ast-explorer.com/) - Visualiser l'AST
- [PHPUnit Test Generator](https://phpunit.de/getting-started/phpunit-10.html)

---

## ✅ Commandes Essentielles

```bash
# Tous les tests
vendor/bin/phpunit tests/Unit/

# Tests spécifiques
vendor/bin/phpunit tests/Unit/Analyzer/Helper/

# Avec couverture
./run-new-tests.sh --coverage

# Un seul test
vendor/bin/phpunit --filter testDetectsSyliusStyle tests/Unit/

# Tests rapides (sans couverture)
php -d xdebug.mode=off vendor/bin/phpunit tests/Unit/
```

---

**Questions ?** Consultez `tests/TESTS_IMPROVEMENTS_2025.md` pour plus de détails.
