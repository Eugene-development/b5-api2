# Проблема: Заказ не отображается на странице финансов

## Описание проблемы

Заказ создан и отображается в модальном окне просмотра проекта в сервисе b5-agent, но не отображается на странице финансов.

## Возможные причины

### 1. Бонус не создаётся при создании заказа

Согласно методу `createBonusForOrder` в `BonusService`, бонус НЕ создаётся если:

```php
if (!$order->is_active || !$order->order_amount || $order->order_amount <= 0) {
    return null;
}
```

**Условия для создания бонуса:**
- `is_active` должен быть `true` (по умолчанию `true` в миграции)
- `order_amount` должен быть указан и больше 0
- В проекте должен быть указан `user_id` (агент)

### 2. Поле order_amount не заполнено

Согласно миграции `2025_12_07_120001_add_bonus_fields_to_orders_table.php`, поле `order_amount` является `nullable`:

```php
$table->decimal('order_amount', 15, 2)
      ->nullable()
      ->after('is_urgent')
      ->comment('Сумма закупки');
```

Если при создании заказа не указана сумма, бонус не будет создан.

### 3. Заказ создан до добавления поля order_amount

Если заказ был создан до миграции, добавляющей поле `order_amount`, то у него может быть `order_amount = null`.

## Диагностика

### Шаг 1: Проверить заказ в базе данных

```sql
SELECT 
    id,
    order_number,
    project_id,
    order_amount,
    agent_percentage,
    is_active,
    status_id,
    created_at
FROM orders
WHERE order_number = 'ORDER-XXXXX-XXX';
```

### Шаг 2: Проверить наличие бонуса

```sql
SELECT 
    ab.id,
    ab.order_id,
    ab.agent_id,
    ab.commission_amount,
    ab.status_id,
    ab.accrued_at,
    bs.code as status_code,
    bs.name as status_name
FROM agent_bonuses ab
LEFT JOIN bonus_statuses bs ON ab.status_id = bs.id
WHERE ab.order_id = '<order_id>';
```

### Шаг 3: Использовать тестовый скрипт

```bash
cd b5-api-2
php test-order-bonus.php <order_id>
```

Скрипт проверит:
- Все параметры заказа
- Условия создания бонуса
- Наличие бонуса
- Попытается создать бонус вручную, если его нет

## Решения

### Решение 1: Установить order_amount для заказа

Если заказ создан без суммы, нужно установить её:

```sql
UPDATE orders
SET order_amount = <сумма>
WHERE id = '<order_id>';
```

После этого нужно создать бонус вручную или пересчитать:

```php
$order = Order::find('<order_id>');
$bonusService = app(\App\Services\BonusService::class);
$bonusService->createBonusForOrder($order);
```

### Решение 2: Пересчитать суммы заказов из позиций

Если заказ имеет позиции (order_positions), можно пересчитать сумму:

```sql
UPDATE orders o
SET order_amount = (
    SELECT COALESCE(SUM(op.total_price), 0)
    FROM order_positions op
    WHERE op.order_id = o.id
)
WHERE o.order_amount IS NULL OR o.order_amount = 0;
```

### Решение 3: Создать миграцию для пересчёта

Создать миграцию, которая:
1. Пересчитает `order_amount` для всех заказов с позициями
2. Создаст бонусы для заказов, у которых их нет

Пример уже существует: `2025_12_08_130000_recalculate_order_amounts.php`

### Решение 4: Обязательное поле order_amount

Если `order_amount` должен быть обязательным, нужно:

1. Обновить миграцию (сделать поле NOT NULL с дефолтом 0)
2. Обновить валидацию в GraphQL/API
3. Обновить форму создания заказа в b5-admin

## Рекомендации

### Для b5-admin (форма создания заказа)

Убедиться, что при создании заказа:
1. Поле `order_amount` обязательно для заполнения
2. Или автоматически рассчитывается из позиций заказа
3. Или устанавливается дефолтное значение 0

### Для API

Добавить валидацию при создании заказа:

```php
'order_amount' => 'required|numeric|min:0'
```

### Для BonusService

Можно добавить логирование, чтобы понимать, почему бонус не создаётся:

```php
public function createBonusForOrder(Order $order): ?AgentBonus
{
    if (!$order->is_active) {
        \Log::warning("Bonus not created: order is not active", ['order_id' => $order->id]);
        return null;
    }
    
    if (!$order->order_amount || $order->order_amount <= 0) {
        \Log::warning("Bonus not created: order_amount is null or zero", [
            'order_id' => $order->id,
            'order_amount' => $order->order_amount
        ]);
        return null;
    }
    
    // ... остальной код
}
```

## Проверка после исправления

1. Проверить, что бонус создан в таблице `agent_bonuses`
2. Проверить, что бонус отображается на странице финансов в b5-agent
3. Проверить, что статистика бонусов обновилась

## Связанные файлы

- `app/Services/BonusService.php` - метод `createBonusForOrder`
- `app/Models/Order.php` - событие `created`
- `app/GraphQL/Queries/AgentBonusesQuery.php` - запрос бонусов
- `database/migrations/2025_12_07_120001_add_bonus_fields_to_orders_table.php`
- `database/migrations/2025_12_08_130000_recalculate_order_amounts.php`

## Дата создания
22 декабря 2024
