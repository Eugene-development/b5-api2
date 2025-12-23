# Решение проблемы: Статус заказа "Не указано"

## Проблема подтверждена

Дефолтный статус существует в базе данных и настроен правильно:
- ✅ Статус "Сформирован" (slug: `formed`)
- ✅ `is_default = true`
- ✅ `is_active = true`

Но при создании заказа статус всё равно не устанавливается.

## Пошаговое решение

### Шаг 1: Очистить все кеши

```bash
cd b5-api-2
./clear-all-cache.sh
```

Или вручную:
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan lighthouse:clear-cache
```

### Шаг 2: Проверить существующий заказ

Если у вас уже есть заказ со статусом "Не указано":

```bash
php check-order-status.php ORDER-XXXXX-XXX
```

Скрипт:
- Покажет текущий статус заказа
- Если статус NULL, автоматически установит дефолтный статус

### Шаг 3: Создать новый заказ

1. Создайте новый заказ в b5-admin
2. Проверьте логи:
```bash
tail -f storage/logs/laravel.log | grep CreateOrder
```

Должны появиться записи:
```
CreateOrder: Getting default status
CreateOrder: Order created
```

3. Проверьте созданный заказ:
```bash
php check-order-status.php ORDER-XXXXX-XXX
```

### Шаг 4: Если проблема сохраняется

Проверьте, что в GraphQL запросе загружается связь `status`:

```graphql
query {
  orders {
    id
    order_number
    status_id
    status {
      id
      value
      slug
    }
  }
}
```

Если `status` возвращает `null`, но `status_id` заполнен, проблема в загрузке связи.

## Возможные причины

### 1. Кеш GraphQL не обновился

**Решение**: Очистить кеш (Шаг 1)

### 2. В b5-admin не загружается связь status

**Проверка**: Посмотреть GraphQL запрос в b5-admin, который загружает заказы.

**Решение**: Убедиться, что запрос включает:
```graphql
status {
  id
  value
  slug
}
```

### 3. Проблема с ULID в связи

Если `status_id` использует ULID, а связь не работает, проверьте модель `OrderStatus`:

```php
// В OrderStatus.php должно быть:
public $incrementing = false;
protected $keyType = 'string';
```

### 4. Транзакция не коммитится

Если используется транзакция в `CreateOrder`, убедитесь, что она успешно коммитится.

## Исправление существующих заказов

Установить дефолтный статус для всех заказов без статуса:

```sql
UPDATE orders 
SET status_id = (
    SELECT id 
    FROM order_statuses 
    WHERE slug = 'formed' 
    LIMIT 1
)
WHERE status_id IS NULL;
```

Или через скрипт:

```bash
php artisan tinker
>>> $defaultStatusId = App\Models\OrderStatus::where('slug', 'formed')->value('id');
>>> App\Models\Order::whereNull('status_id')->update(['status_id' => $defaultStatusId]);
```

## Проверка после исправления

1. Создать новый заказ
2. Проверить в базе данных:
```sql
SELECT o.order_number, os.value as status
FROM orders o
LEFT JOIN order_statuses os ON o.status_id = os.id
ORDER BY o.created_at DESC
LIMIT 5;
```

Все заказы должны иметь статус "Сформирован".

## Утилиты для диагностики

- `check-order-statuses.php` - проверка всех статусов в системе
- `check-order-status.php <order_number>` - проверка конкретного заказа
- `clear-all-cache.sh` - очистка всех кешей
- `test-order-bonus.php <order_id>` - проверка бонуса для заказа

## Дата создания
22 декабря 2024
