# Proposition : Découplage des Analyseurs Statiques et Runtime

## Problématique
Actuellement, tous les analyseurs implémentent `AnalyzerInterface` et sont exécutés au runtime dans le `DataCollector` via une itération sur le conteneur (`iterable $analyzers`).

Ceci pose trois problèmes majeurs :
1. **Surcoût inutile** : Certains analyseurs (ex: `InnoDBEngineAnalyzer`, `CollationAnalyzer`) effectuent des vérifications de structure de base de données qui ne changent pas d'une requête à l'autre.
2. **Performance** : Exécuter ces analyses à chaque requête (runtime) dégrade inutilement le temps de réponse (TTFB).
3. **Complexité du CI** : Il est impossible aujourd'hui d'exécuter ces analyses de manière isolée dans une pipeline CI pour valider la conformité de l'infrastructure sans avoir une base de données active en mode requête.

## Objectif
Découpler les analyseurs en deux catégories :
1. **`StaticAnalyzerInterface`** : Analyseurs pouvant être exécutés en mode CLI (hors requête), basés sur les métadonnées (Metadata) ou la configuration.
2. **`RuntimeAnalyzerInterface`** : Analyseurs nécessitant le contexte d'une requête HTTP (QueryData, Backtrace, StopWatch).

## Proposition Technique

### 1. Nouveaux Contrats
```php
interface StaticAnalyzerInterface {
    public function analyzeConfiguration(): IssueCollection;
}

interface RuntimeAnalyzerInterface {
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection;
}
```

### 2. Implémentation
*   **Infrastructure CI** : Créer une commande CLI `bin/console doctrine:doctor:analyze:static` qui instancie uniquement les `StaticAnalyzerInterface`.
*   **DataCollector** : Le `DataCollector` ne traitera plus que les `RuntimeAnalyzerInterface`. Les analyseurs statiques seront exécutés via la commande CI et le résultat pourra être mis en cache/dashboard.

### 3. Bénéfices attendus
*   **Gain de performance** : Suppression de l'overhead d'analyse statique durant le cycle de vie de la requête HTTP.
*   **Validation CI/CD** : Intégration dans le pipeline pour bloquer les déploiements si la configuration DB ne respecte pas les standards.
*   **Clarté** : Responsabilité unique pour chaque analyseur.

## Tâches à réaliser
- [ ] Créer les interfaces `StaticAnalyzerInterface` et `RuntimeAnalyzerInterface`.
- [ ] Migrer les analyseurs `src/Analyzer/Configuration/` vers `StaticAnalyzerInterface`.
- [ ] Créer la commande Symfony CLI pour l'exécution statique.
- [ ] Adapter le `DoctrineDoctorDataCollector` pour ne consommer que les analyseurs runtime.
- [ ] Ajouter une étape de vérification statique dans `.github/workflows/ci.yml`.
