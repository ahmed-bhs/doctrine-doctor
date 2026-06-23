# Rapport d'optimisation : Doctrine Doctor

## 1. Vue d'ensemble
Le bundle a subi une transformation majeure pour garantir trois objectifs :
*   **Interopérabilité** : Support natif du mode **DBAL-only** (sans ORM).
*   **Sécurité & Performance** : Détection exhaustive des failles d'injection SQL/DQL et optimisation des outils de diagnostic.
*   **Stabilité en production** : Résilience face aux environnements persistants (FrankenPHP/Worker Mode) et transparence sur les échecs d'analyse.

---

## 2. Travail réalisé (Historique des commits)
Les modifications sont segmentées en 11 commits logiques. Voici les fichiers clés associés :

*   **Sécurité** (`sec: strengthen SQL and DQL injection detection`)
    *   Fichiers : `src/Analyzer/Security/SQLInjectionInRawQueriesAnalyzer.php`, `src/Analyzer/Security/DQLInjectionAnalyzer.php`, `src/Analyzer/Helper/InjectionPatternDetector.php`.
*   **Robustesse DBAL** (`feat: ensure robust DBAL-only support`)
    *   Fichiers : `src/DependencyInjection/Compiler/OrmServicePruner.php`, `src/Collector/DoctrineDoctorDataCollector.php`, `src/Collector/Helper/DatabaseInfoCollector.php`.
*   **Performance** (`perf: optimize issue deduplication`)
    *   Fichiers : `src/Service/IssueDeduplicator.php`, `src/Analyzer/Performance/NPlusOneAnalyzer.php`, `src/Analyzer/Performance/HydrationAnalyzer.php`, `src/Analyzer/UnusedEagerLoadAnalyzer.php`.
*   **Stabilité & Observabilité** (`fix: prevent silent analyzer failures`, `chore: ensure worker mode stability`)
    *   Fichiers : `src/Cache/SqlNormalizationCache.php` (limite mémoire), `src/EventSubscriber/WorkerModeResetSubscriber.php` (clear cache), `src/Collector/DoctrineDoctorDataCollector.php` (Auto-Diagnostic), `src/Analyzer/Performance/MissingIndexAnalyzer.php` (Correction SQLite).
    *   Nettoyage : Suppression de `src/Collector/ServiceHolder.php` et `src/Collector/ServiceHolderData.php`.

---

## 3. Analyse technique & Risques résiduels

| Composant | Choix technique | Justification | Risque résiduel |
| :--- | :--- | :--- | :--- |
| **Collector** | Remplacement de type-hint ORM par `object` | Évite la *Fatal Error* si ORM absent | Faible : Nécessite des `instanceof` manuels |
| **Cache SQL** | Limite à 10k entrées + purge | Prévention des *Memory Leaks* | Cache "froid" après purge périodique |
| **Self-Diagnostic**| Insertion d'issues `ConfigurationIssue` | Rendre les échecs visibles | Faible : Complexité accrue du Collector |

---

## 4. Reste à faire (Roadmap)

### A. Optimisations de performance (Cache statique)
*   **Cacher les résultats d'analyse de configuration** : Analyser les analyseurs comme `InnoDBEngineAnalyzer` ou `CollationAnalyzer` (dans `src/Analyzer/Configuration/`) pour mettre en cache leurs résultats statiques.

### B. Précision des analyseurs
*   **Threshold dynamique** : Faire en sorte que `src/Analyzer/Performance/SlowQueryAnalyzer.php` adapte son seuil (`threshold`) en fonction du driver détecté (ex: plus bas pour SQLite).
*   **Ordre des index composites** : Améliorer la suggestion d'index dans `src/Analyzer/Performance/MissingIndexAnalyzer.php` en triant les colonnes suggérées par leur sélectivité.

### C. Infrastructure de test
*   **Tests de performance automatisés** : Ajouter des tests de non-régression de performance pour mesurer l'impact de l'analyseur sur le temps de réponse total.
