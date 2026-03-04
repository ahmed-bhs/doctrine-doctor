---
layout: default
title: Overview
parent: Contributing
nav_order: 1
---

# Contributing to Doctrine Doctor

Merci de contribuer. Ce guide résume le flux recommandé.

---

## Ways to Contribute

- Report bugs
- Propose features
- Improve documentation
- Submit code changes

---

## Workflow

1. Fork + clone
2. Create branch (`feature/...` ou `fix/...`)
3. Implement changes
4. Run quality checks
5. Open PR

Commandes utiles:

```bash
composer test
composer phpstan
composer ecs
composer check
```

---

## Code Guidelines

- Respecter PSR-12
- Garder les analyzers stateless
- Ajouter des tests pour tout nouveau comportement
- Mettre à jour la doc et le changelog si nécessaire

---

## PR Guidelines

Avant de soumettre:

- [ ] `composer check` passe
- [ ] docs mises à jour
- [ ] changement expliqué clairement dans la PR

Description PR recommandée:

- contexte/problème
- solution technique
- impact (breaking/non-breaking)
- stratégie de test

---

## Help

- Issues: <https://github.com/ahmed-bhs/doctrine-doctor/issues>
- Discussions: <https://github.com/ahmed-bhs/doctrine-doctor/discussions>

---

**[Development Setup →](development)** | **[Creating Analyzers →](creating-analyzers)**
