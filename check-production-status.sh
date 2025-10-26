#!/bin/bash

echo "=== Production Status Check ==="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${YELLOW}1. Checking Git status...${NC}"
git status
echo ""

echo -e "${YELLOW}2. Checking if curator-processing status exists in database...${NC}"
echo "Run this SQL query:"
echo "SELECT id, value, slug FROM project_statuses WHERE slug = 'curator-processing';"
echo ""
echo "Or use Laravel tinker:"
echo "php artisan tinker"
echo ">>> \\App\\Models\\ProjectStatus::where('slug', 'curator-processing')->first()"
echo ""

echo -e "${YELLOW}3. Checking GraphQL schema cache...${NC}"
if [ -f "bootstrap/cache/lighthouse-schema.php" ]; then
    echo -e "${GREEN}✓ Schema cache exists${NC}"
    echo "Last modified: $(stat -f "%Sm" bootstrap/cache/lighthouse-schema.php 2>/dev/null || stat -c "%y" bootstrap/cache/lighthouse-schema.php 2>/dev/null)"
    echo ""
    echo "To clear schema cache:"
    echo "php artisan lighthouse:clear-cache"
else
    echo -e "${YELLOW}⚠ Schema cache not found${NC}"
fi
echo ""

echo -e "${YELLOW}4. Checking config cache...${NC}"
if [ -f "bootstrap/cache/config.php" ]; then
    echo -e "${GREEN}✓ Config cache exists${NC}"
    echo "Last modified: $(stat -f "%Sm" bootstrap/cache/config.php 2>/dev/null || stat -c "%y" bootstrap/cache/config.php 2>/dev/null)"
else
    echo -e "${YELLOW}⚠ Config cache not found${NC}"
fi
echo ""

echo -e "${YELLOW}5. Testing acceptProject mutation availability...${NC}"
echo "Run this query to check if mutation is available:"
echo ""
cat << 'EOF'
curl -X POST https://api.bonus.band/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"{ __type(name: \"Mutation\") { fields { name } } }"}'
EOF
echo ""
echo "Look for 'acceptProject' in the response"
echo ""

echo -e "${YELLOW}6. Checking Laravel logs for recent errors...${NC}"
if [ -f "storage/logs/laravel.log" ]; then
    echo "Last 20 lines:"
    tail -n 20 storage/logs/laravel.log
else
    echo -e "${RED}✗ Log file not found${NC}"
fi
echo ""

echo -e "${YELLOW}7. Quick fix commands:${NC}"
echo "php artisan lighthouse:clear-cache"
echo "php artisan config:clear"
echo "php artisan cache:clear"
echo "php artisan config:cache"
echo "sudo systemctl restart php-fpm"
echo ""

echo -e "${YELLOW}8. Test mutation directly:${NC}"
echo "php artisan tinker"
echo ">>> \$project = \\App\\Models\\Project::first();"
echo ">>> \$user = \\App\\Models\\User::first();"
echo ">>> \$mutation = new \\App\\GraphQL\\Mutations\\AcceptProject();"
echo ">>> \$mutation(null, ['projectId' => \$project->id, 'userId' => \$user->id]);"
