# Doctrine Doctor - Roadmap de Développement

**Objectif Global**: Faire de Doctrine Doctor **LE package de référence** pour l'analyse runtime de Doctrine ORM dans Symfony.

**Vision**: Package open-source maintenu à vie, complémentaire à PHPStan Doctrine, utilisé par tous les devs Symfony qui veulent des applications performantes.

**Date de démarrage**: Janvier 2025
**Statut actuel**: En développement actif

---

## 📊 Bilan Actuel (Janvier 2025)

### ✅ Ce qui est terminé

#### 1. Infrastructure Parser SQL ✅
- [x] Installation `phpmyadmin/sql-parser` v6.0
- [x] Création `SqlStructureExtractor` (167 lignes, 15 tests)
- [x] Support MariaDB + PostgreSQL testé et validé
- [x] Parser réutilisable pour futurs analyzers

#### 2. Migration Regex → Parser ✅
- [x] `JoinOptimizationAnalyzer` migré (-45% code, 41 tests OK)
- [x] `SetMaxResultsWithCollectionJoinAnalyzer` migré (-47% code, 32 tests OK)
- [x] Documentation complète (VERDICT_FINAL.md, PROOF_OF_CONCEPT_RESULTS.md)
- [x] Décision pragmatique: arrêt migration (autres analyzers = ROI faible)

#### 3. Tests & Qualité ✅
- [x] 88 tests passing pour parser + analyzers migrés
- [x] 100% compatibilité MariaDB + PostgreSQL
- [x] Aucune régression introduite

### 📈 Métriques Actuelles

| Métrique | Valeur |
|----------|-------|
| Analyzers totaux | ~40+ |
| Analyzers migrés (parser) | 2 |
| Tests passing | 88 (parser + migrés) |
| Support DB | MariaDB + PostgreSQL  |
| Code réduit (migration) | ~35 lignes |
| Parser réutilisable | Oui |

---

## 🎯 Objectifs Stratégiques

### Objectif 1: Visibilité & Adoption
**Problème**: Package techniquement solide mais peu connu
**Solution**: Marketing technique + démonstrations visuelles

### Objectif 2: Expérience Développeur
**Problème**: Output trop technique, pas assez actionable
**Solution**: Score santé, comparaisons avant/après, quick fixes

### Objectif 3: Intégration DevOps
**Problème**: Uniquement dans Symfony Profiler (dev)
**Solution**: Export JSON, CLI, CI/CD integration

### Objectif 4: Maintenabilité Long Terme
**Problème**: Code parfois complexe (regex, heuristiques)
**Solution**: Parser SQL (fait), documentation, tests

---

## 📋 RESTE À FAIRE - Détails

### Phase 1: Visibilité & Marketing Technique (PRIORITÉ HAUTE)

#### 1.1 README.md Complet ⏳
**Objectif**: Expliquer clairement la valeur unique vs PHPStan
**Temps estimé**: 1-2h
**Impact**: ⭐⭐⭐⭐⭐


### Phase 3: Améliorations Techniques (PRIORITÉ MOYENNE)

#### 3.1 Améliorer Analyseurs Existants ⏳
**Objectif**: Réduire faux positifs, améliorer précision
**Temps estimé**: 2-3h par analyzer
**Impact**: ⭐⭐⭐⭐

- [ ] **N+1 Analyzer: Réduire faux positifs**
  - Problème: Détecte parfois N+1 sur des queries intentionnelles (batch loading)
  - Solution: Détecter patterns "batch loading" (IN clause avec ≥10 IDs)
  - Ajouter option `@DoctrineDoctorIgnore` en annotation

- [ ] **MissingIndexAnalyzer: Améliorer suggestions**
  - Problème: Suggère parfois des index inutiles
  - Solution: Analyser cardinalité des colonnes
  - Ne suggérer que si rows_examined > 1000

- [ ] **JoinOptimizationAnalyzer: Détecter JOIN inefficaces**
  - Nouveau: Détecter JOINs sur colonnes non-indexées
  - Nouveau: Suggérer index composite pour JOINs multiples

- [ ] **LazyLoadingAnalyzer: Améliorer détection**
  - Problème: Ne détecte que les patterns simples
  - Solution: Utiliser PHP parser pour détecter boucles + getters

**Critères de succès**:
- Moins de faux positifs (< 5%)
- Suggestions plus précises
- Tests pour edge cases

---

#### 3.2 Nouveaux Analyzeurs ⏳
**Objectif**: Couvrir plus de cas d'usage
**Temps estimé**: 4-6h par analyzer
**Impact**: ⭐⭐⭐

**Prioritaire**:

- [ ] **SuboptimalQueryAnalyzer**
  - Détecte: `SELECT *` au lieu de colonnes spécifiques
  - Détecte: `COUNT(*)` avec JOIN (peut utiliser subquery)
  - Détecte: `WHERE` sur colonne calculée (impossible à indexer)

- [ ] **CachingOpportunityEnhancedAnalyzer**
  - Détecte: Requêtes identiques exécutées plusieurs fois (même request)
  - Suggère: Result cache Doctrine
  - Suggère: Query cache
  - Estime: Temps économisé si cache activé

- [ ] **PaginationAnalyzer**
  - Détecte: `setMaxResults()` sans `setFirstResult()` (pas de vraie pagination)
  - Détecte: Pagination sur table massive sans index (lent)
  - Suggère: Keyset pagination pour grandes tables

**Optionnel**:

- [ ] **TransactionAnalyzer**
  - Détecte: Queries en dehors de transaction (auto-commit)
  - Détecte: Transactions trop longues (> 1000 queries)
  - Suggère: Batching avec flush() + clear()

- [ ] **ConnectionPoolAnalyzer**
  - Détecte: Connection pool saturé
  - Suggère: Augmenter pool size
  - Avertit: Potential connection leak

**Critères de succès**:
- 3-5 nouveaux analyzers
- Tests complets
- Documentation dans ANALYZERS.md

---

### Phase 4: UX & Polish (PRIORITÉ MOYENNE)

#### 4.1 Quick Fixes Intégrés ⏳
**Objectif**: Corriger directement depuis le profiler
**Temps estimé**: 6-8h
**Impact**: ⭐⭐⭐⭐⭐ (grosse feature)

- [ ] **Backend: Apply fix command**
  ```php
  // bin/console doctrine:doctor:fix <issue-id>

  class FixCommand extends Command
  {
      protected function execute(InputInterface $input, OutputInterface $output): int
      {
          $issueId = $input->getArgument('issue-id');
          $issue = $this->issueRepository->find($issueId);

          if (!$issue->hasAutoFix()) {
              $output->writeln('No auto-fix available for this issue');
              return Command::FAILURE;
          }

          $fixer = $this->fixerFactory->createFixer($issue->getType());
          $result = $fixer->fix($issue);

          if ($result->isSuccess()) {
              $output->writeln('✅ Fix applied successfully!');
              $output->writeln('Before: ' . $result->getBefore());
              $output->writeln('After: ' . $result->getAfter());
          }

          return Command::SUCCESS;
      }
  }
  ```

- [ ] **Fixers à implémenter**

  **N+1 Fixer**:
  ```php
  // BEFORE:
  $products = $repo->findAll();
  foreach ($products as $p) {
      $p->getCategory()->getName();
  }

  // AFTER (auto-généré):
  $products = $repo->createQueryBuilder('p')
      ->leftJoin('p.category', 'c')
      ->addSelect('c')
      ->getQuery()->getResult();
  ```

  **Missing Index Fixer**:
  ```php
  // Generate migration:
  // bin/console doctrine:doctor:fix missing-index-product-category

  // Creates: migrations/VersionXXX_add_index_product_category.php
  CREATE INDEX IDX_product_category ON product (category_id);
  ```

  **LEFT JOIN → INNER JOIN Fixer**:
  ```php
  // BEFORE:
  ->leftJoin('o.customer', 'c')
  ->where('c.email IS NOT NULL')

  // AFTER:
  ->innerJoin('o.customer', 'c')
  ```

- [ ] **Frontend: Bouton "Apply Fix"**
  ```html
  ┌─────────────────────────────────────────────────────────┐
  │ ⚠️  N+1 Query Detected (47 queries)                     │
  ├─────────────────────────────────────────────────────────┤
  │ Problem: Loading Category for each Product             │
  │ Location: ProductController.php:45                      │
  │                                                         │
  │ [View Code] [View Suggestion] [🔧 Apply Fix]           │
  └─────────────────────────────────────────────────────────┘
  ```

**Critères de succès**:
- 3-5 fixers implémentés
- Boutons dans profiler
- Génération de code propre
- Backup avant apply
- Tests e2e

---

#### 4.2 Filtres & Recherche Profiler ⏳
**Objectif**: Naviguer facilement dans les issues
**Temps estimé**: 2-3h
**Impact**: ⭐⭐⭐

- [ ] **Filtres**
  - Par severity (Critical / Warning / Info)
  - Par catégorie (Performance / Security / Best Practices)
  - Par analyzer
  - Par fichier source

- [ ] **Recherche**
  - Search bar: chercher par mot-clé
  - Highlight résultats
  - Recherche dans description + backtrace

- [ ] **Tri**
  - Par severity (défaut)
  - Par temps d'exécution
  - Par nombre d'occurrences
  - Par fichier

**Critères de succès**:
- Filtres fonctionnels
- Recherche rapide
- UX fluide

---

### Phase 5: Communauté & Ecosystem (PRIORITÉ BASSE)

#### 5.1 Packagist & Distribution ✅
**Statut**: Déjà fait, juste maintenir

- [x] Package publié sur Packagist
- [ ] Releases régulières (semantic versioning)
- [ ] Changelog.md maintenu à jour
- [ ] Tags Git pour chaque release

---

#### 5.2 Documentation Officielle Symfony ⏳
**Objectif**: Être référencé dans docs Symfony/Doctrine
**Temps estimé**: 2-3h rédaction + patience
**Impact**: ⭐⭐⭐⭐⭐

- [ ] **Pull Request vers Symfony Docs**
  - Fichier: `docs/doctrine/performance.rst`
  - Section: "Runtime Analysis with Doctrine Doctor"
  - Lien vers repo GitHub

- [ ] **Pull Request vers Doctrine Docs**
  - Fichier: `docs/en/reference/working-with-objects.rst`
  - Section: "Debugging N+1 Queries"
  - Mention Doctrine Doctor

**Critères de succès**:
- PR mergée dans docs Symfony
- PR mergée dans docs Doctrine
- Lien officiel vers le package

---

#### 5.3 Articles de Blog & Talks ⏳
**Objectif**: Faire connaître le package
**Temps estimé**: Variable
**Impact**: ⭐⭐⭐⭐⭐

- [ ] **Article: "N+1 Queries: How to Detect Them in Symfony"**
  - Publier sur Medium / dev.to
  - Démo avec Doctrine Doctor
  - Comparaison PHPStan vs Doctrine Doctor
  - Code exemples concrets

- [ ] **Article: "5 Performance Issues PHPStan Can't Catch"**
  - N+1 avec conditions runtime
  - Missing indexes sur vraies données
  - Requêtes lentes (> 1s)
  - Transactions mal optimisées
  - Cache opportunities

- [ ] **Talk SymfonyLive / SymfonyCon (optionnel)**
  - Titre: "Runtime Analysis: The Missing Piece in Symfony Performance"
  - Démo live avec Doctrine Doctor
  - Avant/après sur app réelle

**Critères de succès**:
- 2-3 articles publiés
- 1000+ vues par article
- Feedback positif communauté

---

## 🗓️ Planning Suggéré

### Sprint 1 (Semaine 1-2): Visibilité
- [ ] README.md complet
- [ ] 3 GIFs démo
- [ ] Score "Doctrine Health"

### Sprint 2 (Semaine 3-4): Features
- [ ] Comparaison Avant/Après
- [ ] Export JSON
- [ ] Documentation technique (ARCHITECTURE, ANALYZERS)

### Sprint 3 (Semaine 5-6): Polish
- [ ] Quick Fixes (3 fixers min)
- [ ] Filtres & Recherche profiler
- [ ] Tests e2e

### Sprint 4 (Semaine 7-8): Communauté
- [ ] Articles de blog (2 min)
- [ ] PRs vers docs Symfony/Doctrine
- [ ] Release 2.0

---

## 📊 KPIs de Succès

### Adoption
- [ ] 1000+ installations Packagist (6 mois)
- [ ] 100+ stars GitHub (3 mois)
- [ ] 10+ contributeurs (1 an)

### Qualité
- [ ] 90%+ tests passing
- [ ] < 5% faux positifs
- [ ] Temps d'analyse < 100ms/requête

### Visibilité
- [ ] Référencé dans docs Symfony
- [ ] 2-3 articles de blog publiés
- [ ] 1 talk conférence (optionnel)

---

## 📝 Notes de Maintenance

### Tâches Récurrentes

**Chaque release**:
- [ ] Mettre à jour CHANGELOG.md
- [ ] Tests de régression complets
- [ ] Update version dans composer.json
- [ ] Git tag + release GitHub
- [ ] Annonce sur Twitter/Reddit

**Chaque mois**:
- [ ] Review issues GitHub
- [ ] Merge PRs communauté
- [ ] Update README si nouveaux features

**Chaque trimestre**:
- [ ] Audit sécurité (dependencies)
- [ ] Review performances analyzers
- [ ] Update docs si Symfony/Doctrine évoluent

---

## 🎯 Prochaine Action

**La TOUTE prochaine chose à faire** (par ordre de priorité):

1. ✅ **README.md Section PHPStan** (1h)
   - Ajouter tableau comparatif
   - Expliquer complémentarité
   - Quick start amélioré

2. ✅ **GIF Démo N+1** (1h)
   - Capturer screen Symfony Profiler
   - Montrer N+1 détecté
   - Optimiser pour < 5MB

3. ✅ **Score Doctrine Health** (4h)
   - Backend: HealthScoreCalculator
   - Frontend: Affichage profiler
   - Tests

**À chaque fois qu'une tâche est terminée**, cocher la case ✅ et passer à la suivante!

---

**Dernière mise à jour**: 2025-01-13
**Prochaine review**: Chaque semaine
