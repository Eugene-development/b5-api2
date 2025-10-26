#!/bin/bash

echo "=== CORS Diagnostics for api.bonus.band ==="
echo ""

echo "1. Testing OPTIONS request (preflight):"
curl -I -X OPTIONS https://api.bonus.band/graphql \
  -H "Origin: https://admin.bonus.band" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type"
echo ""

echo "2. Testing POST request:"
curl -I -X POST https://api.bonus.band/graphql \
  -H "Origin: https://admin.bonus.band" \
  -H "Content-Type: application/json" \
  -d '{"query":"{ __typename }"}'
echo ""

echo "3. Checking Laravel config cache:"
php artisan config:show cors
echo ""

echo "4. Checking if CORS middleware is loaded:"
php artisan route:list | grep graphql
echo ""

echo "=== Instructions ==="
echo "Expected headers in response:"
echo "  - Access-Control-Allow-Origin: https://admin.bonus.band"
echo "  - Access-Control-Allow-Credentials: true"
echo "  - Access-Control-Allow-Methods: POST, GET, OPTIONS, ..."
echo ""
echo "If headers are missing, run:"
echo "  php artisan config:clear"
echo "  php artisan config:cache"
echo "  sudo systemctl restart php-fpm"
