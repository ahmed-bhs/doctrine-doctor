.PHONY: help install test rector phpstan qa fix check

## Colors
COLOR_RESET   = \033[0m
COLOR_INFO    = \033[32m
COLOR_COMMENT = \033[33m

## Help
help:
	@echo "${COLOR_COMMENT}Usage:${COLOR_RESET}"
	@echo ""
	@echo "${COLOR_INFO}  make install${COLOR_RESET}      Install dependencies"
	@echo "${COLOR_INFO}  make test${COLOR_RESET}         Run tests"
	@echo "${COLOR_INFO}  make phpstan${COLOR_RESET}      Run PHPStan analysis"
	@echo "${COLOR_INFO}  make rector${COLOR_RESET}       Run Rector (dry-run)"
	@echo "${COLOR_INFO}  make rector-fix${COLOR_RESET}   Run Rector and apply fixes"
	@echo "${COLOR_INFO}  make qa${COLOR_RESET}           Run all quality checks (tests + phpstan + rector)"
	@echo "${COLOR_INFO}  make fix${COLOR_RESET}          Fix code with Rector"
	@echo "${COLOR_INFO}  make check${COLOR_RESET}        Check code without fixing"
	@echo "${COLOR_INFO}  make baseline${COLOR_RESET}     Generate PHPStan baseline"
	@echo "${COLOR_INFO}  make coverage${COLOR_RESET}     Generate test coverage report"
	@echo ""

## Install
install:
	@echo "${COLOR_INFO}Installing dependencies...${COLOR_RESET}"
	composer install

## Tests
test:
	@echo "${COLOR_INFO}Running tests...${COLOR_RESET}"
	vendor/bin/phpunit

test-coverage:
	@echo "${COLOR_INFO}Running tests with coverage...${COLOR_RESET}"
	vendor/bin/phpunit --coverage-html coverage/

## PHPStan
phpstan:
	@echo "${COLOR_INFO}Running PHPStan...${COLOR_RESET}"
	vendor/bin/phpstan analyse --memory-limit=1G

phpstan-baseline:
	@echo "${COLOR_INFO}Generating PHPStan baseline...${COLOR_RESET}"
	vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G

## Rector
rector:
	@echo "${COLOR_INFO}Running Rector (dry-run)...${COLOR_RESET}"
	vendor/bin/rector process --dry-run

rector-fix:
	@echo "${COLOR_INFO}Running Rector and applying fixes...${COLOR_RESET}"
	vendor/bin/rector process

## Combined commands
check: test phpstan rector
	@echo "${COLOR_INFO}✓ All checks passed!${COLOR_RESET}"

fix: rector-fix
	@echo "${COLOR_INFO}✓ Code fixed!${COLOR_RESET}"

qa: check
	@echo "${COLOR_INFO}✓ Quality assurance complete!${COLOR_RESET}"

## Coverage
coverage:
	@echo "${COLOR_INFO}Generating coverage report...${COLOR_RESET}"
	vendor/bin/phpunit --coverage-html=coverage/
	@echo "${COLOR_INFO}Coverage report generated in coverage/index.html${COLOR_RESET}"

## Clean
clean:
	@echo "${COLOR_INFO}Cleaning cache...${COLOR_RESET}"
	rm -rf var/cache/rector
	rm -rf .phpunit.cache
	rm -rf coverage/

## CI
ci: install check coverage
	@echo "${COLOR_INFO}✓ CI pipeline complete!${COLOR_RESET}"
