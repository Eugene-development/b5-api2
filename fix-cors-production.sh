#!/bin/bash

echo "=== Fixing CORS on Production ==="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Step 1: Pull latest code
echo -e "${YELLOW}Step 1: Pulling latest code...${NC}"
git pull
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Code updated${NC}"
else
    echo -e "${RED}✗ Failed to pull code${NC}"
    exit 1
fi
echo ""

# Step 2: Check if changes are present
echo -e "${YELLOW}Step 2: Verifying changes...${NC}"
if grep -q "HandleCors" bootstrap/app.php; then
    echo -e "${GREEN}✓ CORS middleware found in bootstrap/app.php${NC}"
else
    echo -e "${RED}✗ CORS middleware NOT found in bootstrap/app.php${NC}"
    exit 1
fi

if grep -q "admin.bonus.band" config/cors.php; then
    echo -e "${GREEN}✓ admin.bonus.band found in CORS config${NC}"
else
    echo -e "${RED}✗ admin.bonus.band NOT found in CORS config${NC}"
    exit 1
fi
echo ""

# Step 3: Clear all caches
echo -e "${YELLOW}Step 3: Clearing Laravel caches...${NC}"
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo -e "${GREEN}✓ Caches cleared${NC}"
echo ""

# Step 4: Cache config
echo -e "${YELLOW}Step 4: Caching configuration...${NC}"
php artisan config:cache
echo -e "${GREEN}✓ Config cached${NC}"
echo ""

# Step 5: Check PHP-FPM service name
echo -e "${YELLOW}Step 5: Detecting PHP-FPM service...${NC}"
if systemctl list-units --type=service | grep -q "php8.2-fpm"; then
    PHP_FPM_SERVICE="php8.2-fpm"
elif systemctl list-units --type=service | grep -q "php8.1-fpm"; then
    PHP_FPM_SERVICE="php8.1-fpm"
elif systemctl list-units --type=service | grep -q "php-fpm"; then
    PHP_FPM_SERVICE="php-fpm"
else
    echo -e "${RED}✗ PHP-FPM service not found${NC}"
    echo "Please restart PHP-FPM manually"
    PHP_FPM_SERVICE=""
fi

if [ ! -z "$PHP_FPM_SERVICE" ]; then
    echo -e "${GREEN}✓ Found service: $PHP_FPM_SERVICE${NC}"
    echo ""

    # Step 6: Restart PHP-FPM
    echo -e "${YELLOW}Step 6: Restarting PHP-FPM...${NC}"
    sudo systemctl restart $PHP_FPM_SERVICE

    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ PHP-FPM restarted${NC}"

        # Check status
        if systemctl is-active --quiet $PHP_FPM_SERVICE; then
            echo -e "${GREEN}✓ PHP-FPM is running${NC}"
        else
            echo -e "${RED}✗ PHP-FPM is not running!${NC}"
            sudo systemctl status $PHP_FPM_SERVICE
        fi
    else
        echo -e "${RED}✗ Failed to restart PHP-FPM${NC}"
    fi
fi
echo ""

# Step 7: Test CORS
echo -e "${YELLOW}Step 7: Testing CORS...${NC}"
echo "Testing OPTIONS request:"
RESPONSE=$(curl -s -I -X OPTIONS https://api.bonus.band/graphql \
  -H "Origin: https://admin.bonus.band" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type")

if echo "$RESPONSE" | grep -q "Access-Control-Allow-Origin"; then
    echo -e "${GREEN}✓ CORS headers present${NC}"
    echo "$RESPONSE" | grep "Access-Control"
else
    echo -e "${RED}✗ CORS headers missing!${NC}"
    echo "Full response:"
    echo "$RESPONSE"
fi
echo ""

# Final instructions
echo -e "${YELLOW}=== Next Steps ===${NC}"
echo "1. Check nginx config for conflicting CORS headers:"
echo "   sudo cat /etc/nginx/sites-available/api.bonus.band | grep -i cors"
echo ""
echo "2. If nginx has CORS headers, remove them and reload:"
echo "   sudo nginx -t && sudo systemctl reload nginx"
echo ""
echo "3. Test in browser:"
echo "   - Open https://admin.bonus.band"
echo "   - Open DevTools (F12) → Network"
echo "   - Try to accept a project"
echo "   - Check response headers for Access-Control-Allow-Origin"
echo ""
echo -e "${GREEN}Done!${NC}"
