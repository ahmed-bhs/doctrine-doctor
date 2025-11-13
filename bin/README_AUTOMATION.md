# Scripts d'Automatisation - Quick Reference

> 🚀 **4 scripts pour automatiser 90% de la migration regex**

---

## 📋 Quick Commands

### 1️⃣ Analyser tous les regex

```bash
php bin/analyze-regex-patterns.php
# → Génère: docs/REGEX_ANALYSIS_REPORT.md
```

### 2️⃣ Générer les tests

```bash
php bin/generate-regex-tests.php
# → Génère: tests/Unit/Pattern/*.php

# Lancer les tests
vendor/bin/phpunit tests/Unit/Pattern/
```

### 3️⃣ Convertir automatiquement (DRY RUN)

```bash
php bin/auto-convert-simple-regex.php --dry-run
# → Affiche ce qui SERAIT changé (sans toucher aux fichiers)
```

### 4️⃣ Convertir pour de vrai

```bash
php bin/auto-convert-simple-regex.php
# → Convertit + crée des backups .regex-backup
# → Génère: docs/REGEX_CONVERSION_REPORT.md
```

### 5️⃣ Rollback si problème

```bash
php bin/auto-convert-simple-regex.php --restore
# → Restaure depuis les backups
```

### 6️⃣ Linter le code

```bash
php bin/lint-regex-patterns.php
# → Détecte les mauvais patterns
```

---

## 🎯 Workflow Complet (1 heure)

```bash
# 1. Analyser (5 min)
php bin/analyze-regex-patterns.php
cat docs/REGEX_ANALYSIS_REPORT.md

# 2. Générer tests (5 min)
php bin/generate-regex-tests.php
vendor/bin/phpunit tests/Unit/Pattern/

# 3. Convertir en DRY RUN (5 min)
php bin/auto-convert-simple-regex.php --dry-run

# 4. Convertir pour de vrai (10 min)
php bin/auto-convert-simple-regex.php
cat docs/REGEX_CONVERSION_REPORT.md

# 5. Tester (30 min)
vendor/bin/phpunit

# 6. Linter (5 min)
php bin/lint-regex-patterns.php

# 7. Commit si OK
git add .
git commit -m "refactor: migrate simple regex to str_contains()"
```

---

## 🛡️ Sécurité

Tous les scripts sont **sécurisés**:
- ✅ Mode `--dry-run` pour tester sans risque
- ✅ Backups automatiques (`.regex-backup`)
- ✅ Rollback en 1 commande (`--restore`)
- ✅ Rapports détaillés de tous les changements

---

## 📚 Documentation Complète

- **Guide complet**: `docs/AUTOMATION_SCRIPTS.md`
- **Ce qu'on peut automatiser**: `docs/WHAT_CAN_BE_AUTOMATED.md`
- **Plan de migration**: `docs/REGEX_MIGRATION_PRAGMATIC.md`

---

## 🆘 En cas de problème

```bash
# Restaurer les backups
php bin/auto-convert-simple-regex.php --restore

# OU revenir à la branche de backup
git checkout backup/pre-regex-migration-2025-01-13
```

---

**Créé**: 2025-01-13
