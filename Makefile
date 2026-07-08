.PHONY: help install test rector phpstan qa fix check

COLOR_RESET   = \033[0m
COLOR_INFO    = \033[32m
COLOR_COMMENT = \033[33m

## Usage
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

install: ## Install dependencies
	@echo "${COLOR_INFO}Installing dependencies...${COLOR_RESET}"
	composer install

test: ## Run tests
	@echo "${COLOR_INFO}Running tests...${COLOR_RESET}"
	vendor/bin/phpunit

test-coverage: ## Run tests with coverage
	@echo "${COLOR_INFO}Running tests with coverage...${COLOR_RESET}"
	vendor/bin/phpunit --coverage-html coverage/

phpstan: ## Run PHPStan analysis
	@echo "${COLOR_INFO}Running PHPStan...${COLOR_RESET}"
	vendor/bin/phpstan analyse --memory-limit=1G

phpstan-baseline: ## Generate PHPStan baseline
	@echo "${COLOR_INFO}Generating PHPStan baseline...${COLOR_RESET}"
	vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G

rector: ## Run Rector (dry-run)
	@echo "${COLOR_INFO}Running Rector (dry-run)...${COLOR_RESET}"
	vendor/bin/rector process --dry-run

rector-fix: ## Run Rector and apply fixes
	@echo "${COLOR_INFO}Running Rector and applying fixes...${COLOR_RESET}"
	vendor/bin/rector process

## Combined commands
check: test phpstan rector ## Check code without fixing
	@echo "${COLOR_INFO}✓ All checks passed!${COLOR_RESET}"

fix: rector-fix ## Fix code with Rector
	@echo "${COLOR_INFO}✓ Code fixed!${COLOR_RESET}"

qa: check ## Run all quality checks (tests + phpstan + rector)
	@echo "${COLOR_INFO}✓ Quality assurance complete!${COLOR_RESET}"

coverage: ## Generate test coverage report
	@echo "${COLOR_INFO}Generating coverage report...${COLOR_RESET}"
	vendor/bin/phpunit --coverage-html=coverage/
	@echo "${COLOR_INFO}Coverage report generated in coverage/index.html${COLOR_RESET}"

clean: ## Clean project
	@echo "${COLOR_INFO}Cleaning cache...${COLOR_RESET}"
	rm -rf var/cache/rector
	rm -rf .phpunit.cache
	rm -rf coverage/

ci: install check coverage ## Run CI
	@echo "${COLOR_INFO}✓ CI pipeline complete!${COLOR_RESET}"
