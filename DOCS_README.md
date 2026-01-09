# Documentation Guide

This file explains how to build and maintain the Doctrine Doctor documentation.

---

## Overview

Documentation is built with **MkDocs Material** and automatically deployed to GitHub Pages.

**Live Documentation**: https://ahmed-bhs.github.io/doctrine-doctor/

---

## Local Development

### Prerequisites

Install Python 3.x and pip, then install MkDocs:

```bash
pip install -r requirements.txt
```

Or install individually:

```bash
pip install mkdocs-material
pip install mkdocs-minify-plugin
```

### Preview Documentation Locally

Start the development server:

```bash
mkdocs serve
```

Open your browser to http://127.0.0.1:8000

The site will automatically reload when you save changes to markdown files.

---

## Building Documentation

### Build Static Site

```bash
mkdocs build
```

This creates a `site/` directory with static HTML files.

### Validate Build

```bash
mkdocs build --strict
```

Fails if there are any warnings (broken links, missing files, etc.).

---

## Documentation Structure

```text
docs/
├── index.md                    # Homepage
├── CONFIGURATION.md            # Configuration reference
├── ANALYZERS.md                # All analyzers catalog
├── ARCHITECTURE.md             # System architecture
├── TEMPLATE_SECURITY.md        # Template security guide
│
├── getting-started/
│   ├── quick-start.md         # 30-second setup guide
│   ├── installation.md        # Detailed installation
│   └── first-steps.md         # Getting started tutorial
│
├── examples/
│   ├── n-plus-one.md          # N+1 query examples
│   ├── missing-indexes.md     # Index optimization
│   └── security.md            # Security issues
│
├── contributing/
│   ├── overview.md            # How to contribute
│   ├── development.md         # Dev environment setup
│   └── creating-analyzers.md  # Custom analyzers guide
│
├── about/
│   ├── license.md             # MIT License
│   └── changelog.md           # Version history
│
├── images/                     # Images and assets
├── stylesheets/
│   └── extra.css              # Custom CSS
└── javascripts/
    └── extra.js               # Custom JavaScript
```

---

## Writing Documentation

### Markdown Format

Use GitHub-flavored Markdown with MkDocs extensions:

#### Code Blocks

````markdown
```php
<?php
// PHP code here
```
````

#### Admonitions

```markdown
!!! note "Title"
    Content here

!!! tip
    Helpful tip

!!! warning
    Warning message

!!! danger
    Critical warning
```

#### Tabs

```markdown
=== "Tab 1"
    Content for tab 1

=== "Tab 2"
    Content for tab 2
```

#### Tables

```markdown
| Column 1 | Column 2 |
|----------|----------|
| Value 1  | Value 2  |
```

### Adding New Pages

1. Create markdown file in appropriate directory
2. Add to navigation in `mkdocs.yml`:

```yaml
nav:
  - Category:
      - Page Title: path/to/file.md
```

### Images

Place images in `docs/images/` and reference:

```markdown
![Alt text](images/filename.png)
```

---

## Deployment

### Automatic Deployment

Documentation is automatically deployed when you push to `main` branch:

```bash
git add docs/ mkdocs.yml
git commit -m "docs: update documentation"
git push origin main
```

GitHub Actions will:
1. Build the documentation
2. Deploy to `gh-pages` branch
3. Publish to https://ahmed-bhs.github.io/doctrine-doctor/

### Manual Deployment

```bash
mkdocs gh-deploy
```

This builds and pushes to the `gh-pages` branch.

---

## Configuration

### mkdocs.yml

Main configuration file:

```yaml
site_name: Doctrine Doctor
theme:
  name: material
  palette:
    - scheme: default      # Light mode
    - scheme: slate        # Dark mode
  features:
    - navigation.instant
    - navigation.tracking
    - search.suggest
```

### Theme Customization

- **CSS**: Edit `docs/stylesheets/extra.css`
- **JavaScript**: Edit `docs/javascripts/extra.js`
- **Colors**: Modify `theme.palette` in `mkdocs.yml`

---

## Best Practices

### Writing Style

- Use clear, concise language
- Include code examples
- Add context and explanations
- Use consistent terminology

### Code Examples

- Always test code examples
- Include necessary imports
- Show both problem and solution
- Add comments for clarity

### Links

- Use relative links for internal pages: `[Link](../page.md)`
- Use absolute URLs for external links
- Check for broken links before committing

### Images

- Use descriptive alt text
- Optimize image size
- Use PNG for screenshots
- Use SVG for diagrams when possible

---

## Troubleshooting

### Build Fails

```bash
# Clear cache
rm -rf site/ .cache/

# Rebuild
mkdocs build --clean
```

### Broken Links

```bash
# Use strict mode to find broken links
mkdocs build --strict
```

### Styling Issues

- Clear browser cache
- Check `extra.css` syntax
- Verify CSS class names

---

## Resources

- [MkDocs Documentation](https://www.mkdocs.org/)
- [Material for MkDocs](https://squidfunk.github.io/mkdocs-material/)
- [Markdown Guide](https://www.markdownguide.org/)
- [GitHub Pages](https://pages.github.com/)

---

## Questions?

For documentation-related questions:

- [GitHub Issues](https://github.com/ahmed-bhs/doctrine-doctor/issues)
- [GitHub Discussions](https://github.com/ahmed-bhs/doctrine-doctor/discussions)
