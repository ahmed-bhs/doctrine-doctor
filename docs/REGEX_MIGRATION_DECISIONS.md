# Décisions de Migration Regex → Parser
## Référence Rapide par Fichier

> **Légende**:
> - ✅ **MIGRER**: Bénéfice clair, effort raisonnable
> - ⚠️ **PARTIEL**: Migrer certains patterns uniquement
> - ❌ **GARDER**: Regex fonctionne bien, coût migration > bénéfice
> - 🔒 **CRITIQUE**: Nécessite attention particulière (sécurité)

---

## 📁 Analyseurs par Décision

### ✅ À MIGRER (Priorité Haute)

#### 1. SlowQueryAnalyzer.php
**Lignes**: 96, 100, 104, 108, 112
**Patterns**: 5 détections de mots-clés SQL
**Décision**: ✅ **MIGRER vers `str_contains()`**
**Effort**: 30 minutes
**Raison**: Patterns ultra-simples, gain immédiat de performance et lisibilité

```php
// AVANT
preg_match('/ORDER BY/i', $sql)
preg_match('/GROUP BY/i', $sql)

// APRÈS
stripos($sql, 'ORDER BY') !== false
stripos($sql, 'GROUP BY') !== false
```

---

#### 2. FindAllAnalyzer.php
**Lignes**: 93, 98, 101, 115
**Patterns**: 4 patterns simples
**Décision**: ✅ **MIGRER vers `str_contains()` + extraction simple**
**Effort**: 30 minutes
**Raison**: Détection triviale, extraction FROM simple

```php
// AVANT
preg_match('/^SELECT/', $sql)
preg_match('/\sWHERE\s/i', $sql)
preg_match('/FROM\s+(\w+)/i', $sql, $matches)

// APRÈS
str_starts_with(trim($sql), 'SELECT')
str_contains($sql, ' WHERE ')
// Pour FROM: utiliser SqlStructureExtractor (Phase 2)
```

---

#### 3. JoinOptimizationAnalyzer.php ⭐ **CRITIQUE**
**Lignes**: 257, 272, 291, 392, 454, 459
**Patterns**: 6 patterns complexes pour JOIN
**Décision**: ✅ **MIGRER vers SQL Parser** (PhpMyAdmin/sql-parser)
**Effort**: 8-12 heures
**Raison**: Regex trop complexes, fragiles, SQL parser est LA solution

```php
// AVANT (20+ lignes de regex complexes)
preg_match_all(
    '/\b(LEFT\s+OUTER|LEFT|INNER|RIGHT)?\s*JOIN\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i',
    $sql,
    $matches
)

// APRÈS (SQL Parser)
use PhpMyAdmin\SqlParser\Parser;

$parser = new Parser($sql);
foreach ($parser->statements[0]->join as $join) {
    $type = $join->type;
    $table = $join->expr->table;
    $alias = $join->expr->alias;
    $on = $join->on;
}
```

**Impact**: Gère correctement:
- Subqueries dans JOIN
- Parenthèses imbriquées
- CTEs (Common Table Expressions)
- Alias complexes

---

#### 4. GetReferenceAnalyzer.php
**Lignes**: 214, 221, 262, 273, 314
**Patterns**: 5 patterns (détection + extraction)
**Décision**: ✅ **MIGRER vers SQL Parser + `str_contains()`**
**Effort**: 2-3 heures
**Raison**: Mix simple/complexe, uniformiser avec SqlStructureExtractor

```php
// Patterns simples
preg_match('/\bJOIN\b/i', $sql) → str_contains()

// Patterns extraction
preg_match('/FROM\s+([^\s]+)/i', $sql) → SqlStructureExtractor::extractMainTable()
```

---

#### 5. EntityManagerInEntityAnalyzer.php
**Lignes**: 249-256 (8 patterns)
**Patterns**: Détection `$this->em->flush()`, etc.
**Décision**: ✅ **MIGRER vers PhpCodeParser**
**Effort**: 2-3 heures (PhpCodeParser existe déjà!)
**Raison**: Réutilise infrastructure existante, évite faux positifs

```php
// AVANT (8 regex en boucle)
foreach ($emPatterns as $pattern) {
    if (preg_match($pattern, $code)) {
        // ...
    }
}

// APRÈS (PhpCodeParser)
$phpParser = new PhpCodeParser();
if ($phpParser->hasMethodCall($method, '*->em->flush')) {
    // Plus robuste, ignore comments/strings
}
```

---

#### 6. OrderByWithoutLimitAnalyzer.php
**Lignes**: 75, 80
**Patterns**: Détection LIMIT + extraction ORDER BY
**Décision**: ✅ **MIGRER vers `str_contains()` + SQL Parser**
**Effort**: 1-2 heures
**Raison**: Simple + un pattern complexe

```php
// Simple
preg_match('/\b(?:LIMIT|OFFSET)\b/i', $sql) → str_contains()

// Complexe (garder pour Phase 2)
Pattern ORDER BY → SqlStructureExtractor::extractOrderBy()
```

---

#### 7. EagerLoadingAnalyzer.php
**Lignes**: 86
**Patterns**: 1 pattern simple
**Décision**: ✅ **MIGRER vers `str_contains()`** ou garder regex
**Effort**: 10 minutes
**Raison**: Comptage simple de JOINs

```php
// AVANT
preg_match_all('/\bJOIN\b/i', $sql, $matches)
$joinCount = count($matches[0]);

// APRÈS (option 1: keep regex, it's fine)
// APRÈS (option 2: substr_count)
$joinCount = substr_count(strtoupper($sql), 'JOIN');
```

---

#### 8. SensitiveDataExposureAnalyzer.php
**Lignes**: 251, 252, 294, 340
**Patterns**: Détection `json_encode($this)`, accès champs sensibles
**Décision**: ✅ **MIGRER vers PhpCodeParser**
**Effort**: 4-6 heures
**Raison**: Analyse de code PHP, PhpParser plus fiable

```php
// Créer visitors spécifiques
class SerializationVisitor extends NodeVisitorAbstract
{
    // Détecte json_encode($this), serialize($this)
}

class SensitiveFieldAccessVisitor extends NodeVisitorAbstract
{
    // Détecte accès à $entity->password, etc.
}
```

---

#### 9. InsecureRandomAnalyzer.php
**Lignes**: 158, 169
**Patterns**: Détection fonctions insecure (rand, mt_rand, etc.)
**Décision**: ✅ **MIGRER vers PhpCodeParser**
**Effort**: 2-3 heures
**Raison**: Analyse de code, évite détection dans strings

---

#### 10. DQLValidationAnalyzer.php
**Lignes**: 141, 207, 208, 245, 268, 303, 344, 498
**Patterns**: 8 patterns variés
**Décision**: ✅ **MIGRER vers SQL Parser + patterns simples**
**Effort**: 4-6 heures
**Raison**: Mix extraction (SQL Parser) + détection simple (str_contains)

```php
// Simple
preg_match('/\st\d+_/', $sql) → str_contains($sql, 't0_') || str_contains($sql, 't1_')

// Complexe
preg_match_all('/FROM\s+([\w\\\\]+)/i', $dql) → SqlStructureExtractor::extractEntities()
```

---

### ⚠️ MIGRATION PARTIELLE

#### 11. NPlusOneAnalyzer.php
**Lignes**: 96, 99, 103, 106, 109, 122, 134, 146, 198
**Patterns**: 9 patterns (normalisation + détection)
**Décision**: ⚠️ **MIGRER PARTIELLEMENT**
**Effort**: 6-8 heures

**Garder** (simples):
```php
preg_replace('/\s+/', ' ', trim($sql)) // ✅ Whitespace normalization
preg_replace('/=\s*\?/', '= ?', $sql)  // ✅ Simple cleanup
```

**Migrer** (complexes - Phase 4):
```php
// String/numeric replacement → SqlTokenizer
preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql)
preg_replace('/\b(\d+)\b/', '?', $sql)
preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $sql)

// N+1 pattern detection → SQL Parser
preg_match('/FROM\s+(\w+).*WHERE.*(\w+)_id\s*=/i', $sql)
```

---

#### 12. QueryCachingOpportunityAnalyzer.php
**Lignes**: 307, 313, 319, 324, 330, 357, 361
**Patterns**: 7 patterns (normalisation)
**Décision**: ⚠️ **MÊME QUE NPlusOneAnalyzer**
**Effort**: 4-6 heures
**Raison**: Code quasi-identique, même approche

---

#### 13. MissingIndexAnalyzer.php
**Lignes**: 355, 434, 466, 476, 504, 636, 639, 642, 645, 648, 662
**Patterns**: 11 patterns (extraction EXPLAIN + normalisation)
**Décision**: ⚠️ **MIGRER PARTIELLEMENT**
**Effort**: 6-8 heures

**Garder** (patterns EXPLAIN MySQL):
```php
preg_match('/rows=(\d+)/i', $explain)        // ✅ EXPLAIN parsing
preg_match('/(?:SCAN|SEARCH)\s+(\w+)/i', $explain)  // ✅ Table scan detection
```

**Migrer** (normalisation):
```php
// Normalisation query → SqlTokenizer (Phase 4)
```

---

#### 14. PartialObjectAnalyzer.php
**Lignes**: 163-168, 182, 236, 241
**Patterns**: 7 patterns (normalisation + extraction)
**Décision**: ⚠️ **MÊME QUE NPlusOneAnalyzer**
**Effort**: 4-6 heures

---

#### 15. DTOHydrationAnalyzer.php
**Lignes**: 168-173
**Patterns**: 4 patterns (normalisation)
**Décision**: ⚠️ **MÊME QUE NPlusOneAnalyzer**
**Effort**: 2-3 heures

---

#### 16. CollectionInitializationAnalyzer.php
**Lignes**: 167, 169, 189, 191, 194, 197
**Patterns**: 6 patterns
**Décision**: ⚠️ **DÉJÀ FAIT pour la plupart**
**Effort**: 0-1 heure (juste intégration)

**Garder** (fonctionnent bien):
```php
preg_replace('/\/\/.*$/m', '', $code)      // ✅ Comment removal
preg_replace('/\/\*.*?\*\//s', '', $code)  // ✅ Comment removal
```

**Déjà migré**:
- `TraitCollectionInitializationDetectorV2` utilise `PhpCodeParser`
- Patterns ArrayCollection/array → déjà gérés par PhpCodeParser

**Action**: Intégrer TraitCollectionInitializationDetectorV2 comme méthode principale

---

### 🔒 MIGRATION CRITIQUE (Sécurité)

#### 17. DQLInjectionAnalyzer.php 🔒
**Lignes**: 156, 162, 168, 174, 180, 187, 193
**Patterns**: 7 patterns de détection d'injection
**Décision**: 🔒 **APPROCHE HYBRIDE** (Regex + Tokenizer)
**Effort**: 12-16 heures
**Raison**: Sécurité critique, nécessite double validation

**Approche recommandée**:
```php
class ImprovedDQLInjectionDetector
{
    // Phase 1: Quick regex scan (GARDER patterns existants)
    private function quickScan(string $sql): int
    {
        $risk = 0;
        if (preg_match("/'.*(?:UNION|OR\s+1\s*=\s*1).*'/i", $sql)) {
            $risk += 3; // High risk
        }
        // ... autres patterns
        return $risk;
    }

    // Phase 2: Deep token analysis (NOUVEAU)
    private function tokenAnalysis(string $sql): array
    {
        $tokenizer = new SqlTokenizer($sql);
        $issues = [];

        // Analyser tokens pour détecter:
        // - SQL keywords dans string literals
        // - Pattern injection avancés
        // - Encoding attacks

        return $issues;
    }

    public function analyze(string $sql): IssueCollection
    {
        // Quick scan first
        $risk = $this->quickScan($sql);

        // Deep analysis only if suspicious (performance)
        if ($risk > 2) {
            $issues = $this->tokenAnalysis($sql);
        }

        return $issues;
    }
}
```

**Tests requis**:
- Corpus de 100+ injections SQL réelles
- Tests avec SQLMap payloads
- Benchmark faux positifs vs faux négatifs
- **Peer review sécurité obligatoire** ⚠️

---

#### 18. SQLInjectionInRawQueriesAnalyzer.php 🔒
**Lignes**: 137, 178-190, 196, 317-357, 375-377
**Patterns**: 15+ patterns variés
**Décision**: 🔒 **APPROCHE HYBRIDE**
**Effort**: 10-14 heures
**Raison**: Même approche que DQLInjectionAnalyzer

**Garder** (simple et efficace):
```php
preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)/i', $sql)  // ✅ Query type detection
preg_match('/\$_(GET|POST|REQUEST)/i', $code)             // ✅ Superglobal detection
```

**Améliorer** (avec tokenizer):
```php
// Détection concatenation → PhpParser
preg_match('/[\'"].*[\'"]\s*\.\s*\$\w+/', $code)

// Détection sprintf avec SQL → PhpParser
preg_match('/sprintf\s*\(.*SELECT.*\)/is', $code)
```

---

#### 19. QueryBuilderBestPracticesAnalyzer.php
**Lignes**: 109-114, 131, 137, 144, 150, 158
**Patterns**: 6 patterns (injection + best practices)
**Décision**: ⚠️ **MIGRER PARTIELLEMENT**
**Effort**: 3-4 heures

**Migrer**:
```php
// Injection detection (lignes 109-114) → ImprovedSqlInjectionDetector

// LIKE detection → garder (simple)
preg_match('/LIKE/i', $sql) → str_contains()
```

**Garder**:
```php
preg_match('/\w+\s*[!=]=\s*NULL/i', $sql)  // ✅ NULL comparison
preg_match('/IN\s*\(\s*\)/i', $sql)        // ✅ Empty IN
preg_match_all('/:(\w+)/', $sql)           // ✅ Parameter extraction
```

---

### ❌ NE PAS MIGRER (Garder Regex)

#### 20. NullComparisonAnalyzer.php
**Lignes**: 73
**Patterns**: 1 pattern
**Décision**: ❌ **GARDER**
**Raison**: Pattern simple, efficace, peu de faux positifs

```php
// ✅ GARDER tel quel
const NULL_COMPARISON_PATTERN = '/(\w+(?:\.\w+)?)\s*(=|!=|<>)\s*NULL\b/i';
```

---

#### 21. IneffectiveLikeAnalyzer.php
**Lignes**: 76
**Patterns**: 1 pattern
**Décision**: ❌ **GARDER**
**Raison**: Pattern fonctionne bien, coût migration > bénéfice

```php
// ✅ GARDER tel quel
const LIKE_LEADING_WILDCARD_PATTERN = '/\bLIKE\s+([\'"])(%[^\'\"]+)\1/i';
```

---

#### 22. DivisionByZeroAnalyzer.php
**Lignes**: 43 (const), 48 (const)
**Patterns**: 2 patterns
**Décision**: ❌ **GARDER**
**Raison**: Patterns simples et efficaces

```php
// ✅ GARDER tel quel
const DIVISION_PATTERN = '/(\w+(?:\.\w+)?)\s*\/\s*(\w+(?:\.\w+)?)/';
const PROTECTED_PATTERN = '/NULLIF|COALESCE|CASE\s+WHEN/i';
```

---

#### 23. HydrationAnalyzer.php
**Lignes**: 108
**Patterns**: 1 pattern
**Décision**: ❌ **GARDER** (ou migrer en Phase 2)
**Raison**: Pattern extraction LIMIT simple

```php
// ✅ GARDER ou migrer vers SqlStructureExtractor (Phase 2)
preg_match('/LIMIT\s+(?:(\d+)\s*,\s*)?(\d+)(?:\s+OFFSET\s+\d+)?/i', $sql)
```

---

#### 24. LazyLoadingAnalyzer.php
**Lignes**: 100, 177, 197
**Patterns**: 3 patterns
**Décision**: ⚠️ **MIGRER PARTIELLEMENT**
**Effort**: 1-2 heures

**Garder**:
```php
preg_replace('/^(tbl_|tb_)/', '', $table)  // ✅ Prefix removal simple
preg_match('/^get([A-Z]\w+)/', $method)    // ✅ Getter detection simple
```

**Migrer** (Phase 2):
```php
// Lazy loading pattern → SqlStructureExtractor
preg_match('/SELECT.*FROM.*WHERE.*id\s*=\s*\?/i', $sql)
```

---

#### 25. RepositoryFieldValidationAnalyzer.php
**Lignes**: 91, 118
**Patterns**: 2 patterns
**Décision**: ⚠️ **MIGRER EN PHASE 2**
**Effort**: 1-2 heures
**Raison**: Extraction FROM/columns → SqlStructureExtractor

---

#### 26. JoinTypeConsistencyAnalyzer.php
**Constantes**: 4 patterns constants
**Décision**: ✅ **MIGRER AVEC JoinOptimizationAnalyzer**
**Effort**: Inclus dans Phase 2 (JOIN migration)

---

#### 27. YearFunctionOptimizationAnalyzer.php
**Lignes**: 76
**Patterns**: 1 pattern complexe
**Décision**: ✅ **MIGRER EN PHASE 2** (SQL Parser)
**Effort**: 2-3 heures
**Raison**: Détection fonction SQL → SQL Parser gère parfaitement

---

#### 28. EntityManagerClearAnalyzer.php
**Lignes**: 54
**Patterns**: 1 pattern
**Décision**: ⚠️ **MIGRER EN PHASE 2** (SQL Parser)
**Effort**: 30 minutes
**Raison**: Extraction table DML → SqlStructureExtractor

---

## 📊 Résumé par Phase

### Phase 1: Quick Wins (2-4h)
- ✅ SlowQueryAnalyzer
- ✅ FindAllAnalyzer
- ✅ OrderByWithoutLimitAnalyzer
- ✅ EagerLoadingAnalyzer
- ✅ GetReferenceAnalyzer (patterns simples)
- ✅ QueryBuilderBestPracticesAnalyzer (patterns simples)

**Total**: 6 fichiers, 20+ patterns simples

---

### Phase 2: SQL Parser (15-20h)
- ✅ JoinOptimizationAnalyzer ⭐ CRITIQUE
- ✅ JoinTypeConsistencyAnalyzer
- ✅ GetReferenceAnalyzer (patterns complexes)
- ✅ DQLValidationAnalyzer
- ✅ PartialObjectAnalyzer (extraction)
- ✅ YearFunctionOptimizationAnalyzer
- ✅ EntityManagerClearAnalyzer
- ✅ RepositoryFieldValidationAnalyzer
- ✅ LazyLoadingAnalyzer (pattern complexe)
- ✅ HydrationAnalyzer

**Total**: 10 fichiers, extraction SQL structurée

---

### Phase 3: PHP Parser (10-15h)
- ✅ EntityManagerInEntityAnalyzer
- ✅ SensitiveDataExposureAnalyzer
- ✅ InsecureRandomAnalyzer
- ✅ CollectionInitializationAnalyzer (intégration)

**Total**: 4 fichiers, analyse code PHP

---

### Phase 4: Query Normalization (14-18h)
- ✅ NPlusOneAnalyzer
- ✅ QueryCachingOpportunityAnalyzer
- ✅ MissingIndexAnalyzer
- ✅ DTOHydrationAnalyzer
- ✅ PartialObjectAnalyzer (normalisation)

**Total**: 5 fichiers, tokenizer SQL

---

### Phase 5: Security (22-30h) 🔒
- 🔒 DQLInjectionAnalyzer
- 🔒 SQLInjectionInRawQueriesAnalyzer
- 🔒 QueryBuilderBestPracticesAnalyzer (injection)

**Total**: 3 fichiers, détection injection hybride

---

### ❌ Ne Pas Migrer
- ❌ NullComparisonAnalyzer
- ❌ IneffectiveLikeAnalyzer
- ❌ DivisionByZeroAnalyzer

**Total**: 3 fichiers, regex OK

---

## 🎯 Priorisation

### Critique (Faire en premier)
1. 🔥 JoinOptimizationAnalyzer (fragile, impact majeur)
2. 🔥 Phase 1 (quick wins, ROI immédiat)
3. 🔒 DQLInjectionAnalyzer (sécurité)

### Important (Faire rapidement)
4. GetReferenceAnalyzer
5. DQLValidationAnalyzer
6. EntityManagerInEntityAnalyzer

### Utile (Faire quand temps disponible)
7. Query Normalization (Phase 4)
8. Code PHP analysis (Phase 3)

### Optionnel (Nice to have)
9. Remaining extractors
10. Performance optimizations

---

## 📝 Notes Importantes

1. **Tests obligatoires** après chaque migration
2. **Benchmark performance** avant/après
3. **Peer review** pour Phase 5 (Security) ⚠️
4. **Documentation** mise à jour
5. **Changelog** détaillé

---

**Dernière mise à jour**: 2025-01-12
**Statut**: Prêt pour exécution
