# 📚 Documentation Setup - Complete Guide

Votre documentation Doctrine Doctor est maintenant prête à être déployée sur GitHub Pages avec MkDocs Material !

---

## ✅ Ce qui a été créé

### 1. Configuration MkDocs

- **`mkdocs.yml`** - Configuration principale avec thème Material, navigation, extensions
- **`requirements.txt`** - Dépendances Python pour MkDocs
- **`.github/workflows/deploy-docs.yml`** - Déploiement automatique sur GitHub Pages

### 2. Structure de documentation complète

```
docs/
├── index.md                          # Page d'accueil
├── CONFIGURATION.md                  # Guide de configuration
├── ANALYZERS.md                      # Catalogue des analyseurs
├── ARCHITECTURE.md                   # Architecture du système
├── TEMPLATE_SECURITY.md              # Sécurité des templates
│
├── getting-started/
│   ├── quick-start.md               # Installation rapide (30 secondes)
│   ├── installation.md              # Installation détaillée
│   └── first-steps.md               # Premiers pas
│
├── examples/
│   ├── n-plus-one.md                # Exemples N+1 queries
│   ├── missing-indexes.md           # Exemples index manquants
│   └── security.md                  # Exemples sécurité
│
├── contributing/
│   ├── overview.md                  # Guide de contribution
│   ├── development.md               # Setup développement
│   └── creating-analyzers.md        # Créer des analyseurs custom
│
├── about/
│   ├── license.md                   # Licence MIT
│   └── changelog.md                 # Historique des versions
│
├── stylesheets/extra.css            # CSS personnalisé
├── javascripts/extra.js             # JS personnalisé
└── images/                          # Assets (logo, etc.)
```

### 3. Guides et documentation

- **`DOCS_README.md`** - Guide pour maintenir la documentation
- **`.github/GITHUB_PAGES_SETUP.md`** - Guide d'activation GitHub Pages

---

## 🚀 Comment activer GitHub Pages (5 minutes)

### Étape 1: Commiter et pousser

```bash
git add .
git commit -m "docs: add MkDocs Material documentation and GitHub Pages setup"
git push origin main
```

### Étape 2: Activer GitHub Pages

1. Allez sur https://github.com/ahmed-bhs/doctrine-doctor
2. Cliquez sur **Settings** (menu du haut)
3. Cliquez sur **Pages** (menu de gauche)
4. Sous **Source**:
   - Branch: **gh-pages** (sera créée automatiquement)
   - Folder: **/ (root)**
5. Cliquez **Save**

### Étape 3: Configurer les permissions

1. Allez dans **Settings** → **Actions** → **General**
2. Sous **Workflow permissions**:
   - ✅ Sélectionnez **Read and write permissions**
   - ✅ Cochez **Allow GitHub Actions to create and approve pull requests**
3. Cliquez **Save**

### Étape 4: Attendre le déploiement

1. Allez dans l'onglet **Actions**
2. Le workflow "Deploy Documentation" va se lancer automatiquement
3. Attendez 2-5 minutes pour le premier déploiement
4. Votre documentation sera disponible à:
   ```
   https://ahmed-bhs.github.io/doctrine-doctor/
   ```

---

## 🎨 Fonctionnalités de la documentation

### Interface moderne
- ✅ Design Material Design
- ✅ Mode sombre/clair automatique
- ✅ Navigation intuitive avec onglets
- ✅ Recherche intégrée avec suggestions
- ✅ Responsive (mobile, tablette, desktop)

### Fonctionnalités avancées
- ✅ Copie de code en un clic
- ✅ Coloration syntaxique pour PHP, SQL, YAML, Bash
- ✅ Onglets pour comparaison code (Problème/Solution)
- ✅ Admonitions (Notes, Tips, Warnings, Danger)
- ✅ Tables of contents automatiques
- ✅ Liens vers GitHub pour éditer les pages
- ✅ Support des diagrammes Mermaid

### Contenu riche
- ✅ Exemples de code pratiques
- ✅ Guides pas-à-pas
- ✅ Tableaux de configuration
- ✅ Badges de sévérité (Critical, High, Medium, Low)
- ✅ Screenshots et démos

---

## 📝 Comment modifier la documentation

### En local (recommandé)

```bash
# Installer MkDocs
pip install -r requirements.txt

# Lancer le serveur de développement
mkdocs serve

# Ouvrir http://127.0.0.1:8000
# Les modifications sont visibles en temps réel
```

### Éditer les fichiers

```bash
# Éditer une page existante
vim docs/examples/n-plus-one.md

# Créer une nouvelle page
vim docs/examples/my-new-example.md

# Ajouter à la navigation dans mkdocs.yml
```

### Déployer les modifications

```bash
git add docs/
git commit -m "docs: update examples"
git push origin main

# Le déploiement est automatique via GitHub Actions
```

---

## 🎯 Prochaines étapes

### 1. Tester localement

```bash
pip install -r requirements.txt
mkdocs serve
```

Ouvrez http://127.0.0.1:8000 pour prévisualiser.

### 2. Activer GitHub Pages

Suivez les étapes dans la section "Comment activer GitHub Pages" ci-dessus.

### 3. Personnaliser (optionnel)

- **Logo**: Remplacez `docs/images/logo.png`
- **Couleurs**: Modifiez `theme.palette` dans `mkdocs.yml`
- **CSS**: Éditez `docs/stylesheets/extra.css`
- **Navigation**: Ajustez `nav:` dans `mkdocs.yml`

### 4. Ajouter du contenu

- Complétez les exemples
- Ajoutez des screenshots
- Créez des tutoriels vidéo
- Traduisez en d'autres langues

---

## 🔧 Commandes utiles

```bash
# Prévisualiser en local
mkdocs serve

# Construire le site statique
mkdocs build

# Déployer manuellement (si besoin)
mkdocs gh-deploy

# Valider la configuration
mkdocs build --strict

# Nettoyer le cache
rm -rf site/ .cache/
```

---

## 📊 Structure de navigation

La navigation est organisée en 5 sections principales:

1. **Home** - Page d'accueil avec aperçu
2. **Getting Started** - Installation et premiers pas
3. **Documentation** - Guides de référence complets
4. **Examples** - Exemples pratiques par catégorie
5. **Contributing** - Guide pour les contributeurs
6. **About** - License et changelog

---

## 🎨 Personnalisation du thème

### Changer les couleurs

```yaml
# mkdocs.yml
theme:
  palette:
    - scheme: default
      primary: blue      # Changer ici
      accent: indigo     # Et ici
```

Couleurs disponibles: `red`, `pink`, `purple`, `indigo`, `blue`, `cyan`, `teal`, `green`, `lime`, `yellow`, `orange`, `brown`, `grey`

### Ajouter une fonctionnalité

```yaml
theme:
  features:
    - navigation.instant      # Navigation rapide
    - navigation.tabs         # Onglets en haut
    - search.suggest          # Suggestions de recherche
    - content.code.copy       # Bouton copier code
```

---

## 📚 Resources

- **Documentation MkDocs**: https://www.mkdocs.org/
- **Material for MkDocs**: https://squidfunk.github.io/mkdocs-material/
- **Guide Markdown**: https://www.markdownguide.org/
- **GitHub Pages**: https://pages.github.com/

---

## ❓ FAQ

### Q: La documentation ne se met pas à jour ?
**R:** Vérifiez que le workflow GitHub Actions s'est bien exécuté dans l'onglet "Actions". Attendez 5 minutes après le push.

### Q: Comment ajouter une nouvelle page ?
**R:** Créez un fichier `.md` dans `docs/`, puis ajoutez-le dans `nav:` du fichier `mkdocs.yml`.

### Q: Puis-je utiliser un domaine personnalisé ?
**R:** Oui ! Créez `docs/CNAME` avec votre domaine et configurez vos DNS. Voir `.github/GITHUB_PAGES_SETUP.md`.

### Q: Comment voir les changements avant de pousser ?
**R:** Utilisez `mkdocs serve` pour prévisualiser localement.

### Q: La recherche ne fonctionne pas ?
**R:** La recherche nécessite que le site soit déployé. En local, utilisez `mkdocs serve`.

---

## 🎉 C'est prêt !

Votre documentation est maintenant configurée et prête à être déployée. Il vous suffit de:

1. ✅ Commiter et pousser
2. ✅ Activer GitHub Pages
3. ✅ Attendre 5 minutes
4. 🎊 Profiter de votre belle documentation !

**Documentation en ligne sera disponible à:**
```
https://ahmed-bhs.github.io/doctrine-doctor/
```

---

**Créé avec ❤️ par Claude Code**
