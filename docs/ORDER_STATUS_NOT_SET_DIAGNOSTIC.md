# Диагностика: Статус заказа "Не указано" при создании

## Проблема

При создании нового заказа в b5-admin статус отображается как "Не указано", даже если чекбокс "Активен" проставлен.

## Шаги диагностики

### Шаг 1: Проверить статусы в базе данных

```bash
cd b5-api-2
php check-order-statuses.php
```

Скрипт проверит:
- Наличие статусов в таблице `order_statuses`
- Наличие дефолтного статуса (`is_default = true` и `is_active = true`)
- Первый активный статус (fallback)

**Ожидаемый результат**: Должен быть найден статус "Сформирован" (slug: `formed`) с `is_default = true`.

### Шаг 2: Проверить логи при создании заказа

1. Создать новый заказ в b5-admin
2. Проверить логи Laravel:

```bash
tail -f storage/logs/laravel.log | grep CreateOrder
```

Должны появиться записи:
```
CreateOrder: Getting default status
CreateOrder: Order created
```

### Шаг 3: Проверить созданный заказ в базе данных

```sql
SELECT 
    o.id,
    o.order_number,
    o.status_id,
    os.value as status_value,
    os.slug as status_slug
FROM orders o
LEFT JOIN order_statuses os ON o.status_id = os.id
WHERE o.order_number = 'ORDER-XXXXX-XXX';
```

Если `status_id IS NULL`, значит статус не был установлен при создании.

## Возможные причины и решения

### Причина 1: Нет дефолтного статуса в БД

**Проверка**:
```sql
SELECT * FROM order_statuses 
WHERE is_default = true AND is_active = true;
```

**Решение**: Установить `is_default = true` для статуса "Сформирован":
```sql
UPDATE order_statuses 
SET is_default = true 
WHERE slug = 'formed';
```

### Причина 2: Миграция не выполнена

**Проверка**:
```sql
SELECT COUNT(*) FROM order_statuses;
```

Если результат `0`, миграция не выполнена.

**Решение**:
```bash
cd b5-db-2
php artisan migrate
```

### Причина 3: Поле status_id не в fillable

**Проверка**: Открыть `app/Models/Order.php` и проверить массив `$fillable`.

**Решение**: Убедиться, что `'status_id'` есть в массиве `$fillable`:
```php
protected $fillable = [
    // ...
    'status_id',
    // ...
];
```

### Причина 4: GraphQL схема не включает status_id

**Проверка**: Открыть `graphql/order.graphql` и проверить `CreateOrderInput`.

**Решение**: Если `status_id` нет в input, это нормально - он устанавливается автоматически в мутации.

### Причина 5: Кеш GraphQL

**Решение**: Очистить кеш GraphQL:
```bash
cd b5-api-2
php artisan lighthouse:clear-cache
php artisan cache:clear
```

## Проверка после исправления

1. Создать новый заказ в b5-admin
2. Проверить в базе данных:
```sql
SELECT o.order_number, os.value as status
FROM orders o
LEFT JOIN order_statuses os ON o.status_id = os.id
ORDER BY o.created_at DESC
LIMIT 1;
```
3. Статус должен быть "Сформирован", а не NULL

## Временное решение

Если проблема не решается, можно установить статус вручную после создания:

```sql
UPDATE orders 
SET status_id = (SELECT id FROM order_statuses WHERE slug = 'formed' LIMIT 1)
WHERE status_id IS NULL;
```

## Связанные файлы

- `app/GraphQL/Mutations/CreateOrder.php` - мутация создания заказа
- `app/Models/OrderStatus.php` - модель статуса заказа
- `app/Models/Order.php` - модель заказа
- `database/migrations/2025_12_13_120000_create_order_statuses_table.php` - миграция статусов
- `check-order-statuses.php` - скрипт диагностики

## Дата создания
22 декабря 2024
