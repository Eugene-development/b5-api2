#!/bin/bash

echo "=== Testing api.rubonus.pro ==="
echo ""

echo "1. Basic connectivity test:"
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" https://api.rubonus.pro/graphql

echo ""
echo "2. GraphQL introspection query:"
curl -s -X POST https://api.rubonus.pro/graphql \
  -H "Content-Type: application/json" \
  -H "Origin: https://rubonus.pro" \
  -d '{"query":"{ __typename }"}' | head -c 500

echo ""
echo ""
echo "3. Check CORS headers:"
curl -I -X OPTIONS https://api.rubonus.pro/graphql \
  -H "Origin: https://rubonus.pro" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type" 2>/dev/null | grep -i "access-control"

echo ""
echo "4. Full POST request with verbose output:"
curl -v -X POST https://api.rubonus.pro/graphql \
  -H "Content-Type: application/json" \
  -H "Origin: https://rubonus.pro" \
  -H "Accept: application/json" \
  -d '{"query":"{ __typename }"}' 2>&1 | tail -30

echo ""
echo "=== If you see 500 error, check Laravel logs: ==="
echo "tail -f storage/logs/laravel.log"
