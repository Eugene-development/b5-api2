#!/bin/bash

echo "=== Updating GraphQL Schema ==="
echo ""

# Clear Lighthouse schema cache
echo "1. Clearing Lighthouse schema cache..."
php artisan lighthouse:clear-cache

# Clear all Laravel caches
echo "2. Clearing Laravel caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Cache config
echo "3. Caching configuration..."
php artisan config:cache

# Print schema to verify
echo "4. Verifying acceptProject mutation..."
php artisan lighthouse:print-schema | grep -A 5 "acceptProject"

echo ""
echo "✅ Done! Now restart PHP-FPM:"
echo "sudo systemctl restart php-fpm"
