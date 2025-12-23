#!/bin/bash

echo "🧹 Очистка всех кешей..."

php artisan cache:clear
echo "✅ Application cache cleared"

php artisan config:clear
echo "✅ Config cache cleared"

php artisan route:clear
echo "✅ Route cache cleared"

php artisan view:clear
echo "✅ View cache cleared"

php artisan lighthouse:clear-cache
echo "✅ GraphQL cache cleared"

echo ""
echo "✅ Все кеши очищены!"
echo ""
echo "Теперь попробуйте создать новый заказ в b5-admin"
