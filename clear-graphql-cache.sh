#!/bin/bash

# Clear Lighthouse GraphQL cache
php artisan lighthouse:clear-cache

# Clear Laravel cache
php artisan cache:clear
php artisan config:clear

echo "GraphQL cache cleared successfully!"
