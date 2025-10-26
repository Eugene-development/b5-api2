# Шаги для деплоя CORS фикса на продакшен

## Проблема
CORS ошибка на продакшене при нажатии "Принять" в проектах, хотя локально всё работает.

## Возможные причины
1. ❌ Кеш конфигурации Laravel не очищен
2. ❌ Nginx добавляет конфликтующие CORS заголовки
3. ❌ PHP-FPM не перезапущен после изменений
4. ❌ На продакшене другой .env файл

## Решение

### Шаг 1: Подключись к продакшен серверу
```bash
ssh user@your-production-server
```

### Шаг 2: Перейди в директорию API
```bash
cd /path/to/b5-api-2
```

### Шаг 3: Обнови код
```bash
git pull origin main  # или master
```

### Шаг 4: Проверь изменения
```bash
# Проверь что CORS middleware добавлен
cat bootstrap/app.php | grep HandleCors

# Проверь что admin.bonus.band в списке
cat config/cors.php | grep admin.bonus.band
```

### Шаг 5: Очисти кеш Laravel
```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

### Шаг 6: Перезапусти PHP-FPM
```bash
# Для Ubuntu/Debian с PHP 8.x
sudo systemctl restart php8.2-fpm
# или
sudo systemctl restart php-fpm

# Проверь статус
sudo systemctl status php8.2-fpm
```

### Шаг 7: Проверь Nginx конфигурацию
```bash
# Найди конфигурацию для api.bonus.band
sudo cat /etc/nginx/sites-available/api.bonus.band
# или
sudo cat /etc/nginx/conf.d/api.bonus.band.conf
```

**ВАЖНО:** Если в nginx конфигурации есть строки типа:
```nginx
add_header 'Access-Control-Allow-Origin' '*';
add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS';
add_header 'Access-Control-Allow-Headers' '*';
```

**УДАЛИ ИХ!** Laravel должен сам управлять CORS заголовками.

После изменения nginx:
```bash
sudo nginx -t  # Проверка конфигурации
sudo systemctl reload nginx
```

### Шаг 8: Запусти диагностику
```bash
chmod +x diagnose-cors.sh
./diagnose-cors.sh
```

### Шаг 9: Проверь логи
```bash
# Laravel логи
tail -f storage/logs/laravel.log

# Nginx логи
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/access.log

# PHP-FPM логи
sudo tail -f /var/log/php8.2-fpm.log
```

### Шаг 10: Тест из браузера
1. Открой https://admin.bonus.band
2. Открой DevTools (F12) → Network
3. Нажми "Принять" на проекте
4. Проверь запрос к /graphql:
   - **Request Headers** должны содержать: `Origin: https://admin.bonus.band`
   - **Response Headers** должны содержать:
     - `Access-Control-Allow-Origin: https://admin.bonus.band`
     - `Access-Control-Allow-Credentials: true`

## Если проблема остается

### Вариант 1: Проверь что credentials работают
В браузере на admin.bonus.band открой Console и выполни:
```javascript
fetch('https://api.bonus.band/graphql', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  credentials: 'include',
  body: JSON.stringify({ query: '{ __typename }' })
}).then(r => r.json()).then(console.log)
```

### Вариант 2: Временно включи логирование CORS
Добавь в `bootstrap/app.php` перед `HandleCors`:
```php
$middleware->api(prepend: [
    function ($request, $next) {
        \Log::info('CORS Debug', [
            'origin' => $request->header('Origin'),
            'method' => $request->method(),
            'path' => $request->path(),
        ]);
        return $next($request);
    },
    \Illuminate\Http\Middleware\HandleCors::class,
]);
```

### Вариант 3: Проверь SESSION_DOMAIN в .env
На продакшене в `.env` проверь:
```bash
SESSION_DOMAIN=.bonus.band  # Должен быть с точкой для поддоменов
# или
SESSION_DOMAIN=null
```

## Контакты для помощи
Если ничего не помогло, пришли:
1. Вывод `./diagnose-cors.sh`
2. Nginx конфигурацию для api.bonus.band
3. Последние 50 строк из `storage/logs/laravel.log`
