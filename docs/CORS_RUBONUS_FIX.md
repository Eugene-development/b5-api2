# Исправление CORS для домена rubonus.pro

## Проблема

При запросах с `https://rubonus.pro` к `https://api.bonus.band/graphql` возникает ошибка CORS:

```
Access to fetch at 'https://api.bonus.band/graphql' from origin 'https://rubonus.pro' 
has been blocked by CORS policy: No 'Access-Control-Allow-Origin' header is present 
on the requested resource.
```

## Причина

API-сервер не возвращает заголовок `Access-Control-Allow-Origin` для домена `https://rubonus.pro`, хотя домен добавлен в конфигурацию CORS.

Возможные причины:
1. Конфигурация Laravel не обновлена на продакшене (кеш не очищен)
2. Nginx переопределяет CORS заголовки
3. PHP-FPM не перезапущен после изменений

## Решение

### Шаг 1: Проверка текущей конфигурации

Домен уже добавлен в `config/cors.php`:

```php
'allowed_origins' => [
    // ... другие домены
    'https://rubonus.pro',
    'https://www.rubonus.pro',
    'https://auth.rubonus.pro',
],
```

### Шаг 2: Обновление конфигурации на продакшене

Подключитесь к продакшен серверу и выполните:

```bash
cd /path/to/b5-api-2

# Очистить кеш конфигурации
php artisan config:clear

# Пересоздать кеш конфигурации
php artisan config:cache

# Очистить кеш маршрутов (опционально)
php artisan route:clear
php artisan route:cache

# Перезапустить PHP-FPM
sudo systemctl restart php-fpm

# Или перезагрузить nginx
sudo systemctl reload nginx
```

### Шаг 3: Проверка nginx конфигурации

Проверьте конфигурацию nginx для `api.bonus.band`:

```bash
sudo nano /etc/nginx/sites-available/api.bonus.band
# или
sudo nano /etc/nginx/conf.d/api.bonus.band.conf
```

**ВАЖНО:** Убедитесь, что в конфигурации nginx НЕТ строк типа:

```nginx
# ❌ УДАЛИТЬ ИЛИ ЗАКОММЕНТИРОВАТЬ:
add_header 'Access-Control-Allow-Origin' '*';
add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS';
add_header 'Access-Control-Allow-Headers' 'Content-Type';
```

Laravel должен сам управлять CORS заголовками через middleware.

Если вы внесли изменения в nginx:

```bash
# Проверить конфигурацию
sudo nginx -t

# Перезагрузить nginx
sudo systemctl reload nginx
```

### Шаг 4: Диагностика

Запустите скрипт диагностики:

```bash
cd /path/to/b5-api-2
./diagnose-cors-rubonus.sh
```

Ожидаемые заголовки в ответе:

```
Access-Control-Allow-Origin: https://rubonus.pro
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE
Access-Control-Allow-Headers: *
```

### Шаг 5: Тестирование в браузере

1. Откройте `https://rubonus.pro`
2. Откройте DevTools (F12) → Network
3. Попробуйте изменить статус договора
4. Проверьте запрос к `https://api.bonus.band/graphql`
5. Убедитесь, что в Response Headers есть `Access-Control-Allow-Origin: https://rubonus.pro`

## Дополнительная диагностика

### Проверка вручную через curl

```bash
# Проверка OPTIONS (preflight)
curl -I -X OPTIONS https://api.bonus.band/graphql \
  -H "Origin: https://rubonus.pro" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type"

# Проверка POST запроса
curl -v -X POST https://api.bonus.band/graphql \
  -H "Origin: https://rubonus.pro" \
  -H "Content-Type: application/json" \
  -d '{"query":"{ __typename }"}' 2>&1 | grep -i "access-control"
```

### Проверка логов

```bash
# Логи Laravel
tail -f /path/to/b5-api-2/storage/logs/laravel.log

# Логи nginx
sudo tail -f /var/log/nginx/api.bonus.band.error.log
sudo tail -f /var/log/nginx/api.bonus.band.access.log

# Логи PHP-FPM
sudo tail -f /var/log/php-fpm/www-error.log
```

## Почему остальные запросы работают?

Если другие операции на сайте работают корректно, это может означать:
- Браузер кеширует preflight запросы для некоторых endpoints
- Некоторые запросы не требуют preflight (простые GET запросы)
- Проблема специфична для определенных GraphQL мутаций

## Связано ли это с httpOnly cookies?

Нет, проблема не связана с httpOnly cookies. Все запросы в b5-admin уже используют `credentials: 'include'`, что правильно для работы с httpOnly cookies. Проблема именно в отсутствии CORS заголовков от сервера.

## Если проблема сохраняется

1. Проверьте, что изменения в `config/cors.php` закоммичены в git
2. Убедитесь, что на продакшене используется актуальная версия кода
3. Проверьте, нет ли `.env` переменных, переопределяющих CORS настройки
4. Проверьте middleware в `bootstrap/app.php` - должен быть `HandleCors::class`

## Контрольный список

- [ ] Домен `https://rubonus.pro` добавлен в `config/cors.php`
- [ ] Выполнено `php artisan config:clear && php artisan config:cache`
- [ ] Перезапущен PHP-FPM
- [ ] В nginx нет конфликтующих CORS заголовков
- [ ] Скрипт диагностики показывает правильные заголовки
- [ ] Тестирование в браузере успешно
