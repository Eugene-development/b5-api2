#!/bin/sh

set -e

echo "🚀 Starting Laravel application..."

# Создаем необходимые директории
echo "📁 Creating directories..."
mkdir -p /var/www/storage/logs
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/bootstrap/cache

# Устанавливаем правильные права
echo "🔒 Setting permissions..."
chown -R laravel:laravel /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# Очищаем bootstrap cache принудительно
echo "🧹 Clearing bootstrap cache..."
rm -rf /var/www/bootstrap/cache/*

# Переключаемся на пользователя laravel для выполнения artisan команд
echo "🧹 Clearing Laravel cache..."
su-exec laravel php artisan config:clear 2>/dev/null || echo "⚠️  Config clear failed, continuing..."
su-exec laravel php artisan cache:clear 2>/dev/null || echo "⚠️  Cache clear failed, continuing..."
su-exec laravel php artisan route:clear 2>/dev/null || echo "⚠️  Route clear failed, continuing..."
su-exec laravel php artisan view:clear 2>/dev/null || echo "⚠️  View clear failed, continuing..."

# Создаем .env файл если его нет
if [ ! -f /var/www/.env ]; then
    echo "📝 Creating .env file..."
    su-exec laravel cp /var/www/.env.example /var/www/.env 2>/dev/null || echo "⚠️  No .env.example found"
fi

# Генерируем ключ приложения если его нет
echo "🔑 Checking application key..."
su-exec laravel php artisan key:generate --force 2>/dev/null || echo "⚠️  Key generation failed"

echo "✅ Laravel initialization complete!"

# Запуск php-fpm от root пользователя чтобы избежать проблем с логированием
echo "🏃 Starting PHP-FPM..."
exec php-fpm
