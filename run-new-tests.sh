#!/bin/bash

# Script pour lancer les nouveaux tests des améliorations 2025

set -e

echo "🧪 Doctrine Doctor - Tests des Améliorations 2025"
echo "=================================================="
echo ""

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if composer dependencies are installed
if [ ! -d "vendor" ]; then
    echo -e "${YELLOW}⚠️  Installing dependencies...${NC}"
    composer install --no-interaction
fi

echo -e "${BLUE}📋 Running Unit Tests...${NC}"
echo ""

# Run specific new tests
echo -e "${GREEN}✓${NC} TraitCollectionInitializationDetectorTest"
vendor/bin/phpunit tests/Unit/Analyzer/Helper/TraitCollectionInitializationDetectorTest.php --testdox

echo ""
echo -e "${GREEN}✓${NC} CompositionRelationshipDetectorTest"
vendor/bin/phpunit tests/Unit/Analyzer/Helper/CompositionRelationshipDetectorTest.php --testdox

echo ""
echo -e "${GREEN}✓${NC} PhpCodeParserTest"
vendor/bin/phpunit tests/Unit/Analyzer/Parser/PhpCodeParserTest.php --testdox

echo ""
echo -e "${GREEN}✓${NC} CollectionInitializationVisitorTest"
vendor/bin/phpunit tests/Unit/Analyzer/Parser/Visitor/CollectionInitializationVisitorTest.php --testdox

echo ""
echo -e "${GREEN}✓${NC} MethodCallVisitorTest"
vendor/bin/phpunit tests/Unit/Analyzer/Parser/Visitor/MethodCallVisitorTest.php --testdox

echo ""
echo -e "${GREEN}=================================================="
echo -e "✅ All new tests completed!"
echo -e "==================================================${NC}"

# Optional: Run with coverage if requested
if [ "$1" == "--coverage" ]; then
    echo ""
    echo -e "${BLUE}📊 Generating coverage report...${NC}"
    XDEBUG_MODE=coverage vendor/bin/phpunit \
        tests/Unit/Analyzer/Helper/ \
        tests/Unit/Analyzer/Parser/ \
        --coverage-html=coverage/improvements-2025 \
        --coverage-text
    
    echo ""
    echo -e "${GREEN}Coverage report generated in: coverage/improvements-2025/index.html${NC}"
fi
