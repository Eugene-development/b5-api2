#!/bin/bash

echo "=== CORS Diagnostics for rubonus.pro → api.bonus.band ==="
echo ""

echo "1. Testing OPTIONS request (preflight) from rubonus.pro:"
curl -I -X OPTIONS https://api.bonus.band/graphql \
  -H "Origin: https://rubonus.pro" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type"
echo ""

echo "2. Testing POST request from rubonus.pro:"
curl -I -X POST https://api.bonus.band/graphql \
  -H "Origin: https://rubonus.pro" \
  -H "Content-Type: application/json" \
  -d '{"query":"{ __typename }"}'
echo ""

echo "3. Testing with actual GraphQL query:"
curl -v -X POST https://api.bonus.band/graphql \
  -H "Origin: https://rubonus.pro" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"query":"query { __typename }"}' 2>&1 | grep -i "access-control"
echo ""

echo "=== Expected Response Headers ==="
echo "  ✓ Access-Control-Allow-Origin: https://rubonus.pro"
echo "  ✓ Access-Control-Allow-Credentials: true"
echo "  ✓ Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE"
echo "  ✓ Access-Control-Allow-Headers: *"
echo ""

echo "=== If headers are missing, run on production server: ==="
echo "  cd /path/to/b5-api-2"
echo "  php artisan config:clear"
echo "  php artisan config:cache"
echo "  php artisan route:cache"
echo "  sudo systemctl restart php-fpm"
echo "  # or"
echo "  sudo systemctl reload nginx"
