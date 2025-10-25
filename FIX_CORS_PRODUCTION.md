# Исправление CORS на продакшене

## Проблема
При запросах с `https://admin.bonus.band` к `https://api.bonus.band/graphql` возникает ошибка CORS.
Конкретно при нажатии кнопки "Принять" в таблице проектов.

## Причина
Запросы из b5-admin шли с `credentials: 'omit'` (без cookies), что было временным фиксом CORS.
Но для некоторых операций (например, acceptProject) может требоваться аутентификация.

## Решение

### 1. Обновлены файлы в b5-api-2
- `bootstrap/app.php` - добавлен CORS middleware
- `config/cors.php` - улучшена конфигурация allowed_origins

### 2. Обновлены файлы в b5-admin
- `src/lib/api/projects.js` - изменено `credentials: 'omit'` → `credentials: 'include'`
- `src/lib/api/projectStatuses.js` - изменено `credentials: 'omit'` → `credentials: 'include'`
- `src/lib/api/technicalSpecifications.js` - изменено `credentials: 'omit'` → `credentials: 'include'`
- `src/lib/utils/api-test.js` - изменено `credentials: 'omit'` → `credentials: 'include'`

### 3. Деплой на продакшен

#### Шаг 1: Деплой b5-api-2
```bash
cd /path/to/b5-api-2
git pull
php artisan config:clear
php artisan config:cache
sudo systemctl restart php-fpm  # или sudo systemctl reload nginx
```

#### Шаг 2: Деплой b5-admin
```bash
cd /path/to/b5-admin
git pull
npm run build  # или yarn build
# Скопируй build на продакшен сервер
```

### 3. Проверка на продакшене

Убедись, что в `.env` на продакшене НЕТ переопределения CORS настроек.

### 4. Тестирование

После деплоя проверь в браузере:
1. Открой https://admin.bonus.band
2. Открой DevTools (F12) → Network
3. Попробуй нажать кнопку "Принять" в таблице проектов
4. Проверь, что запрос к https://api.bonus.band/graphql успешен

### 5. Если проблема остается

Проверь заголовки ответа от API:
```bash
curl -I -X OPTIONS https://api.bonus.band/graphql \
  -H "Origin: https://admin.bonus.band" \
  -H "Access-Control-Request-Method: POST"
```

Должны быть заголовки:
- `Access-Control-Allow-Origin: https://admin.bonus.band`
- `Access-Control-Allow-Methods: POST, GET, OPTIONS, ...`
- `Access-Control-Allow-Credentials: true`

### 6. Дополнительная проверка nginx

Если используется nginx как reverse proxy, убедись что в конфигурации НЕТ конфликтующих CORS заголовков:

```nginx
# Удали или закомментируй эти строки если они есть:
# add_header 'Access-Control-Allow-Origin' '*';
# add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS';
```

Laravel должен сам управлять CORS заголовками.
