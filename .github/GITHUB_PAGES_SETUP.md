# GitHub Pages Setup Guide

This guide explains how to enable and configure GitHub Pages for Doctrine Doctor documentation.

---

## Quick Setup (One-Time Configuration)

### Step 1: Push Documentation Files

Ensure all documentation files are committed and pushed to the `main` branch:

```bash
git add .
git commit -m "docs: add MkDocs documentation and GitHub Pages setup"
git push origin main
```

### Step 2: Enable GitHub Pages

1. Go to your repository on GitHub
2. Click **Settings** (top menu)
3. Click **Pages** (left sidebar)
4. Under **Source**, select:
   - Source: **Deploy from a branch**
   - Branch: **gh-pages**
   - Folder: **/ (root)**
5. Click **Save**

### Step 3: Wait for Deployment

The GitHub Actions workflow will automatically:

1. Detect changes to `docs/` or `mkdocs.yml`
2. Build the documentation
3. Deploy to the `gh-pages` branch
4. Publish to GitHub Pages

**First deployment takes 2-5 minutes.**

### Step 4: Access Your Documentation

Your documentation will be available at:

```
https://ahmed-bhs.github.io/doctrine-doctor/
```

---

## Manual Trigger (Optional)

If you want to deploy immediately:

1. Go to **Actions** tab
2. Click **Deploy Documentation** workflow
3. Click **Run workflow**
4. Select **main** branch
5. Click **Run workflow**

---

## Configuration Details

### GitHub Actions Workflow

Located at: `.github/workflows/deploy-docs.yml`

```yaml
name: Deploy Documentation

on:
  push:
    branches:
      - main
    paths:
      - 'docs/**'
      - 'mkdocs.yml'
  workflow_dispatch:  # Allows manual trigger
```

**Triggers on**:

- Push to `main` branch
- Changes to `docs/` directory
- Changes to `mkdocs.yml`
- Manual workflow dispatch

### Required Permissions

The workflow needs write permissions to create the `gh-pages` branch.

In your repository settings:

1. Go to **Settings** → **Actions** → **General**
2. Under **Workflow permissions**, select:
   - ✅ **Read and write permissions**
3. Click **Save**

---

## Custom Domain (Optional)

### Setup Custom Domain

1. Go to **Settings** → **Pages**
2. Under **Custom domain**, enter your domain (e.g., `docs.doctrinedoctor.com`)
3. Click **Save**
4. Add DNS records at your domain provider:

```text
Type: CNAME
Name: docs (or your subdomain)
Value: ahmed-bhs.github.io
```

5. Wait for DNS propagation (can take 24-48 hours)
6. Enable **Enforce HTTPS**

### Add CNAME File

Create `docs/CNAME`:

```bash
echo "docs.doctrinedoctor.com" > docs/CNAME
```

Commit and push:

```bash
git add docs/CNAME
git commit -m "docs: add custom domain"
git push origin main
```

---

## Troubleshooting

### Documentation Not Updating

1. **Check workflow status**:
   - Go to **Actions** tab
   - Look for failed workflows
   - Check error logs

2. **Clear GitHub Pages cache**:
   - Settings → Pages
   - Temporarily disable
   - Re-enable and save

3. **Force rebuild**:
   ```bash
   git commit --allow-empty -m "docs: trigger rebuild"
   git push origin main
   ```

### 404 Error

1. **Verify gh-pages branch exists**:
   - Go to **Code** tab
   - Switch to `gh-pages` branch
   - Check if `index.html` exists

2. **Check GitHub Pages settings**:
   - Settings → Pages
   - Verify source is set to `gh-pages` branch

3. **Check repository visibility**:
   - Public repositories: GitHub Pages is free
   - Private repositories: Requires GitHub Pro/Team/Enterprise

### Build Failures

Check workflow logs for errors:

1. Go to **Actions** tab
2. Click on failed workflow
3. Expand failed step
4. Fix errors in documentation
5. Push fixes

Common issues:

- Broken internal links
- Missing images
- Invalid YAML in `mkdocs.yml`
- Syntax errors in markdown

---

## Monitoring

### Check Deployment Status

```bash
# View latest deployment
curl -I https://ahmed-bhs.github.io/doctrine-doctor/

# Should return HTTP 200
```

### Analytics (Optional)

Add Google Analytics to track documentation usage:

```yaml
# mkdocs.yml
extra:
  analytics:
    provider: google
    property: G-XXXXXXXXXX
```

---

## Maintenance

### Update Documentation

```bash
# Edit documentation
vim docs/examples/new-example.md

# Test locally
mkdocs serve

# Commit and push
git add docs/
git commit -m "docs: add new example"
git push origin main

# Automatic deployment happens within 2-5 minutes
```

### Version Documentation

For major releases, consider versioning documentation:

```bash
# Tag release
git tag -a v1.0.0 -m "Release 1.0.0"
git push origin v1.0.0

# Deploy specific version (manual)
mike deploy --push --update-aliases 1.0 latest
```

---

## Resources

- [GitHub Pages Documentation](https://docs.github.com/en/pages)
- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [MkDocs Deployment Guide](https://www.mkdocs.org/user-guide/deploying-your-docs/)
- [Material for MkDocs Publishing](https://squidfunk.github.io/mkdocs-material/publishing-your-site/)

---

## Questions?

For GitHub Pages setup questions:

- [GitHub Community](https://github.community/)
- [Doctrine Doctor Issues](https://github.com/ahmed-bhs/doctrine-doctor/issues)
