# Plan de Refactoring SOLID - SqlStructureExtractor

## État actuel
- **Fichier**: `src/Analyzer/Parser/SqlStructureExtractor.php`
- **Lignes**: 1736
- **Méthodes publiques**: 41
- **Complexité cyclomatique**: 319
- **Warnings PHPMD**: ExcessiveClassLength, TooManyPublicMethods, ExcessiveClassComplexity

## Problèmes identifiés

### Violation du Single Responsibility Principle (SRP)
La classe a **5 responsabilités distinctes**:
1. Extraction de structure SQL (JOINs, tables, colonnes)
2. Normalisation de requêtes (N+1 detection)
3. Détection de patterns (N+1, lazy loading, write operations)
4. Analyse de conditions (WHERE, ON, IS NOT NULL)
5. Analyse de performance (DISTINCT, subqueries, wildcards)

### Violation de l'Interface Segregation Principle (ISP)
- Pas d'interfaces définies
- Tous les analyseurs voient les 41 méthodes publiques
- Dépendances trop larges (couplage élevé)

---

## Architecture cible

### Nouvelle structure

```
src/Analyzer/Parser/
├── Interface/
│   ├── JoinExtractorInterface.php
│   ├── QueryNormalizerInterface.php
│   ├── PatternDetectorInterface.php
│   ├── ConditionAnalyzerInterface.php
│   ├── PerformanceAnalyzerInterface.php
│   └── AggregationAnalyzerInterface.php
├── SqlJoinExtractor.php
├── SqlQueryNormalizer.php
├── SqlPatternDetector.php
├── SqlConditionAnalyzer.php
├── SqlPerformanceAnalyzer.php
├── SqlAggregationAnalyzer.php
└── SqlStructureExtractor.php (FAÇADE - rétrocompatibilité)
```

---

## Répartition des méthodes par classe

### 1. SqlJoinExtractor (Interface: JoinExtractorInterface)
**Responsabilité**: Extraction des JOINs, tables et alias

**Méthodes** (10):
- `extractJoins(string $sql): array`
- `extractMainTable(string $sql): ?array`
- `extractAllTables(string $sql): array`
- `getAllTableNames(string $sql): array`
- `hasTable(string $sql, string $tableName): bool`
- `hasJoin(string $sql): bool`
- `hasJoins(string $sql): bool`
- `countJoins(string $sql): int`
- `extractJoinOnClause(string $sql, string $joinExpression): ?string`
- `extractTableNameWithAlias(string $sql, string $targetAlias): ?array`

**Méthode privée**:
- `normalizeJoinType(string $type): string`

**Taille estimée**: ~300 lignes

---

### 2. SqlQueryNormalizer (Interface: QueryNormalizerInterface)
**Responsabilité**: Normalisation de requêtes pour pattern matching (N+1 detection)

**Méthodes** (4):
- `normalizeQuery(string $sql): string`
- `normalizeSelectForNPlusOne(SelectStatement $statement, string $originalSql): string` (private)
- `normalizeUpdateForNPlusOne(UpdateStatement $statement): string` (private)
- `normalizeDeleteForNPlusOne(DeleteStatement $statement): string` (private)

**Méthodes privées additionnelles**:
- `replaceLiteralsInCondition(string $condition): string`
- `regexBasedNormalization(string $sql): string`

**Taille estimée**: ~280 lignes

---

### 3. SqlPatternDetector (Interface: PatternDetectorInterface)
**Responsabilité**: Détection de patterns de requêtes (N+1, lazy loading, write operations)

**Méthodes** (5):
- `detectNPlusOnePattern(string $sql): ?array`
- `detectNPlusOneFromJoin(string $sql): ?array`
- `detectLazyLoadingPattern(string $sql): ?string`
- `detectUpdateQuery(string $sql): ?string`
- `detectDeleteQuery(string $sql): ?string`
- `detectInsertQuery(string $sql): ?string`
- `isSelectQuery(string $sql): bool`

**Taille estimée**: ~250 lignes

---

### 4. SqlConditionAnalyzer (Interface: ConditionAnalyzerInterface)
**Responsabilité**: Analyse des conditions WHERE, ON, et détection de patterns spécifiques

**Méthodes** (9):
- `extractWhereColumns(string $sql): array`
- `extractWhereConditions(string $sql): array`
- `extractJoinColumns(string $sql): array`
- `extractFunctionsInWhere(string $sql): array`
- `findIsNotNullFieldOnAlias(string $sql, string $alias): ?string`
- `hasComplexWhereConditions(string $sql): bool`
- `hasLocaleConstraintInJoin(string $sql): bool`
- `hasUniqueJoinConstraint(string $sql): bool`
- `isAliasUsedInQuery(string $sql, string $alias, ?string $joinExpression = null): bool`

**Méthode privée**:
- `extractFunctionFromCondition(Condition $condition, array $targetFunctions): ?array`

**Taille estimée**: ~350 lignes

---

### 5. SqlPerformanceAnalyzer (Interface: PerformanceAnalyzerInterface)
**Responsabilité**: Détection de patterns impactant la performance

**Méthodes** (6):
- `hasOrderBy(string $sql): bool`
- `hasLimit(string $sql): bool`
- `hasOffset(string $sql): bool`
- `hasSubquery(string $sql): bool`
- `hasGroupBy(string $sql): bool`
- `hasLeadingWildcardLike(string $sql): bool`
- `hasDistinct(string $sql): bool`
- `getLimitValue(string $sql): ?int`

**Méthode privée**:
- `expressionContainsSubquery(mixed $expr): bool`

**Taille estimée**: ~250 lignes

---

### 6. SqlAggregationAnalyzer (Interface: AggregationAnalyzerInterface)
**Responsabilité**: Analyse des fonctions d'agrégation et clauses associées

**Méthodes** (5):
- `extractAggregationFunctions(string $sql): array`
- `extractGroupByColumns(string $sql): array`
- `extractOrderBy(string $sql): ?string`
- `extractOrderByColumnNames(string $sql): array`
- `extractSelectClause(string $sql): ?string`
- `extractTableAliasesFromSelect(string $sql): array`

**Taille estimée**: ~220 lignes

---

### 7. SqlStructureExtractor (FAÇADE)
**Responsabilité**: Point d'entrée unifié pour rétrocompatibilité

Cette classe devient une **façade** qui délègue aux classes spécialisées:

```php
class SqlStructureExtractor
{
    private JoinExtractorInterface $joinExtractor;
    private QueryNormalizerInterface $queryNormalizer;
    private PatternDetectorInterface $patternDetector;
    private ConditionAnalyzerInterface $conditionAnalyzer;
    private PerformanceAnalyzerInterface $performanceAnalyzer;
    private AggregationAnalyzerInterface $aggregationAnalyzer;

    public function __construct(
        ?JoinExtractorInterface $joinExtractor = null,
        ?QueryNormalizerInterface $queryNormalizer = null,
        ?PatternDetectorInterface $patternDetector = null,
        ?ConditionAnalyzerInterface $conditionAnalyzer = null,
        ?PerformanceAnalyzerInterface $performanceAnalyzer = null,
        ?AggregationAnalyzerInterface $aggregationAnalyzer = null,
    ) {
        $this->joinExtractor = $joinExtractor ?? new SqlJoinExtractor();
        $this->queryNormalizer = $queryNormalizer ?? new SqlQueryNormalizer();
        $this->patternDetector = $patternDetector ?? new SqlPatternDetector();
        $this->conditionAnalyzer = $conditionAnalyzer ?? new SqlConditionAnalyzer();
        $this->performanceAnalyzer = $performanceAnalyzer ?? new SqlPerformanceAnalyzer();
        $this->aggregationAnalyzer = $aggregationAnalyzer ?? new SqlAggregationAnalyzer();
    }

    // Toutes les méthodes publiques actuelles délèguent aux classes spécialisées
    public function extractJoins(string $sql): array
    {
        return $this->joinExtractor->extractJoins($sql);
    }

    public function normalizeQuery(string $sql): string
    {
        return $this->queryNormalizer->normalizeQuery($sql);
    }

    // ... 39 autres méthodes de délégation
}
```

**Taille estimée**: ~200 lignes (uniquement des délégations)

---

## Bénéfices attendus

### ✅ Respect des principes SOLID

1. **Single Responsibility Principle**: Chaque classe a UNE seule responsabilité
2. **Open/Closed Principle**: Extensible sans modification (nouvelles implémentations via interfaces)
3. **Liskov Substitution Principle**: Toute implémentation d'interface est substituable
4. **Interface Segregation Principle**: Interfaces spécialisées, pas de dépendances inutiles
5. **Dependency Inversion Principle**: Dépendances sur abstractions (interfaces)

### ✅ Amélioration de la qualité du code

- **PHPMD warnings**:
  - ❌ AVANT: ExcessiveClassLength (1736 lignes), TooManyPublicMethods (41), ExcessiveClassComplexity (319)
  - ✅ APRÈS: Toutes les classes < 400 lignes, < 15 méthodes publiques par classe

- **Testabilité**:
  - Tests plus ciblés et plus rapides
  - Mocks plus faciles à créer (interfaces)
  - Meilleure couverture de code

- **Maintenabilité**:
  - Code plus clair et plus facile à comprendre
  - Responsabilités bien délimitées
  - Réutilisabilité accrue

### ✅ Rétrocompatibilité

- La façade `SqlStructureExtractor` garde l'API publique actuelle
- Aucune modification nécessaire dans les 21 analyseurs existants
- Migration progressive possible (analyseur par analyseur)

---

## Plan de migration

### Phase 1: Création des interfaces
1. Créer `src/Analyzer/Parser/Interface/` directory
2. Créer les 6 interfaces
3. Valider avec PHPStan level 8

### Phase 2: Création des classes spécialisées
1. Créer `SqlJoinExtractor`
2. Créer `SqlQueryNormalizer`
3. Créer `SqlPatternDetector`
4. Créer `SqlConditionAnalyzer`
5. Créer `SqlPerformanceAnalyzer`
6. Créer `SqlAggregationAnalyzer`

### Phase 3: Transformation de SqlStructureExtractor en façade
1. Ajouter injection de dépendances des 6 classes
2. Remplacer implémentations par délégations
3. Supprimer méthodes privées (déplacées dans classes spécialisées)

### Phase 4: Migration des tests
1. Créer tests pour chaque classe spécialisée
2. Garder tests existants pour la façade (rétrocompatibilité)
3. Atteindre 95%+ de couverture pour chaque classe

### Phase 5: Optimisation des analyseurs (optionnel)
1. Identifier les dépendances réelles de chaque analyseur
2. Injecter uniquement les interfaces nécessaires
3. Réduire le couplage

---

## Métriques attendues

| Métrique | AVANT | APRÈS |
|----------|-------|-------|
| Nombre de classes | 1 | 7 (6 + 1 façade) |
| Lignes par classe | 1736 | ~200-350 |
| Méthodes publiques par classe | 41 | ~5-10 |
| Complexité cyclomatique | 319 | <50 par classe |
| Interfaces | 0 | 6 |
| Warnings PHPMD | 3 | 0 |
| Testabilité | ⭐⭐ | ⭐⭐⭐⭐⭐ |
| Maintenabilité | ⭐⭐ | ⭐⭐⭐⭐⭐ |

---

## Estimation

- **Temps**: 4-6 heures de développement
- **Tests**: 2-3 heures
- **Impact**: Zéro breaking change (rétrocompatibilité totale)
- **Risque**: Très faible (tests existants valident le comportement)

---

## Décision

**Approche recommandée**: Migration progressive en gardant la façade

**Alternative**: Migration radicale (remplacer SqlStructureExtractor partout)
- ⚠️ Risque élevé: modifications dans 21+ analyseurs
- ⚠️ Temps: 8-12 heures
- ❌ Breaking changes

**Verdict**: Privilégier l'approche façade pour garantir la stabilité.
