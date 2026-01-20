---
layout: default
title: Overview
parent: Contributing
nav_order: 1
---

# Contributing to Doctrine Doctor

We welcome contributions from the community! This guide will help you get started.

---

## Ways to Contribute

### 🐛 Report Bugs

Found a bug? [Open an issue](https://github.com/ahmed-bhs/doctrine-doctor/issues/new) with:

- Clear title and description
- Steps to reproduce
- Expected vs actual behavior
- PHP/Symfony/Doctrine versions
- Code samples if applicable

### 💡 Suggest Features

Have an idea for a new analyzer or feature?

1. Check [existing issues](https://github.com/ahmed-bhs/doctrine-doctor/issues) first
2. Open a new issue with the `enhancement` label
3. Describe the use case and expected behavior

### 📝 Improve Documentation

Documentation improvements are always welcome:

- Fix typos or unclear explanations
- Add examples
- Improve code samples
- Translate to other languages

### 🔧 Submit Code

Want to contribute code? Great!

1. [Fork the repository](https://github.com/ahmed-bhs/doctrine-doctor/fork)
2. Create a feature branch
3. Make your changes
4. Submit a pull request

---

## Development Workflow

### 1. Setup Development Environment

See [Development Setup](development) for detailed instructions.

### 2. Choose an Issue

- Check [good first issues](https://github.com/ahmed-bhs/doctrine-doctor/labels/good%20first%20issue)
- Comment on the issue to claim it
- Ask questions if anything is unclear

### 3. Create a Branch

```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/issue-number-description
```

### 4. Make Changes

- Write clean, readable code
- Follow PSR-12 coding standards
- Add tests for new features
- Update documentation

### 5. Test Your Changes

```bash
# Run tests
vendor/bin/phpunit

# Run static analysis
vendor/bin/phpstan analyse

# Run code style checks
vendor/bin/php-cs-fixer fix --dry-run
```

### 6. Commit Your Changes

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```bash
git commit -m "feat: add new analyzer for detecting unused indexes"
git commit -m "fix: correct N+1 detection threshold logic"
git commit -m "docs: add examples for security analyzers"
```

### 7. Push and Create PR

```bash
git push origin feature/your-feature-name
```

Then create a pull request on GitHub with:

- Clear title describing the change
- Reference to related issue (if applicable)
- Description of changes made
- Screenshots/examples if relevant

---

## Code Standards

### Coding Style

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/)
- Use type declarations
- Write PHPDoc for classes and methods
- Use meaningful variable names

Example:

```php
<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Model\IssueCollection;
use AhmedBhs\DoctrineDoctor\Model\QueryDataCollection;

/**
 * Detects N+1 query problems.
 *
 * @author Ahmed EBEN HASSINE
 */
final class NPlusOneAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly int $threshold = 5
    ) {
    }

    /**
     * @param QueryDataCollection $queryDataCollection
     * @return IssueCollection
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        // Implementation
    }
}
```

### Testing

- Write unit tests for new analyzers
- Maintain code coverage above 80%
- Test edge cases
- Use meaningful test names

```php
<?php

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use PHPUnit\Framework\TestCase;

final class NPlusOneAnalyzerTest extends TestCase
{
    public function testDetectsNPlusOneQueryPattern(): void
    {
        // Arrange
        $analyzer = new NPlusOneAnalyzer(threshold: 5);
        $queryData = $this->createNPlusOneScenario();

        // Act
        $issues = $analyzer->analyze($queryData);

        // Assert
        $this->assertCount(1, $issues);
        $this->assertSame('n_plus_one', $issues->first()->getType());
    }
}
```

### Documentation

- Add PHPDoc to all public methods
- Update README.md if adding features
- Add examples for new analyzers
- Keep CHANGELOG.md updated

---

## Pull Request Guidelines

### Before Submitting

- [ ] Code follows PSR-12 standards
- [ ] All tests pass
- [ ] PHPStan analysis passes (Level 8)
- [ ] Documentation updated
- [ ] CHANGELOG.md updated (if applicable)

### PR Description Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Related Issue
Closes #123

## Testing
Describe how you tested the changes

## Screenshots (if applicable)
Add screenshots for UI changes
```

---

## Code Review Process

1. **Automated Checks**: CI runs tests, PHPStan, and code style checks
2. **Manual Review**: Maintainers review code quality and design
3. **Feedback**: Address any requested changes
4. **Approval**: Once approved, your PR will be merged
5. **Release**: Changes will be included in the next release

---

## Creating New Analyzers

See detailed guide: [Creating Analyzers](creating-analyzers)

Quick overview:

1. Implement `AnalyzerInterface`
2. Add analyzer class to appropriate namespace
3. Register as service with tag
4. Write tests
5. Add documentation

---

## Community Guidelines

### Be Respectful

- Be kind and courteous
- Accept constructive criticism
- Focus on what is best for the community
- Show empathy towards others

### Be Patient

- Reviewers are volunteers
- Response time varies
- Complex PRs take longer to review

### Be Clear

- Write clear commit messages
- Provide context in PRs
- Ask questions if unsure

---

## Recognition

Contributors are recognized in:

- [CHANGELOG.md](https://github.com/ahmed-bhs/doctrine-doctor/blob/main/CHANGELOG.md)
- Release notes
- GitHub contributors page

---

## Getting Help

- **Questions**: Open a [discussion](https://github.com/ahmed-bhs/doctrine-doctor/discussions)
- **Bugs**: Open an [issue](https://github.com/ahmed-bhs/doctrine-doctor/issues)
- **Chat**: Join our community channels (coming soon)

---

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

---

**[Development Setup →](development)** | **[Creating Analyzers →](creating-analyzers)**
