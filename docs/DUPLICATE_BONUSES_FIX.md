# Исправление: Дублирование бонусов для заказов

## Проблема

В таблице финансов сервиса b5-agent появлялись две одинаковые строки с одним и тем же заказом.

## Причина

Бонус для заказа создавался дважды:

1. **В событии `created` модели Order** (`app/Models/Order.php`, строка 47):
```php
static::created(function ($order) {
    $bonusService = app(\App\Services\BonusService::class);
    $bonusService->createBonusForOrder($order);
});
```

2. **В мутации CreateOrder** (`app/GraphQL/Mutations/CreateOrder.php`, строка 125):
```php
$bonusService = app(BonusService::class);
$bonusService->createBonusForOrder($order);
```

Это приводило к созданию двух записей в таблице `agent_bonuses` для одного заказа.

## Решение

Удалён дублирующий вызов `createBonusForOrder` из мутации `CreateOrder.php`, так как событие `Order::created` уже автоматически создаёт бонус.

### Код до исправления:

```php
// Автоматически создаём бонус агента
$bonusService = app(BonusService::class);
$bonusService->createBonusForOrder($order);

// Load relationships and return
return $order->load(['positions', 'company', 'project']);
```

### Код после исправления:

```php
// Бонус создаётся автоматически в событии Order::created
// Не нужно создавать вручную, чтобы избежать дубликатов

// Load relationships and return
return $order->load(['positions', 'company', 'project', 'status']);
```

## Удаление существующих дубликатов

### Шаг 1: Проверка наличия дубликатов

```bash
cd b5-api-2
php check-duplicate-bonuses.php [agent_id]
```

Скрипт покажет все дублирующиеся бонусы для заказов и договоров.

### Шаг 2: Удаление дубликатов (dry-run)

```bash
php remove-duplicate-bonuses.php --dry-run
```

Режим проверки - покажет, что будет удалено, но не удалит.

### Шаг 3: Удаление дубликатов

```bash
php remove-duplicate-bonuses.php
```

Скрипт:
- Найдёт все дублирующиеся бонусы
- Оставит самый старый бонус (по `created_at`)
- Удалит остальные дубликаты
- Выведет статистику

**Важно**: Скрипт запросит подтверждение перед удалением.

## Проверка после исправления

### 1. SQL запрос для проверки дубликатов

```sql
-- Проверить дубликаты для заказов
SELECT order_id, COUNT(*) as count
FROM agent_bonuses
WHERE order_id IS NOT NULL
GROUP BY order_id
HAVING count > 1;

-- Проверить дубликаты для договоров
SELECT contract_id, COUNT(*) as count
FROM agent_bonuses
WHERE contract_id IS NOT NULL
GROUP BY contract_id
HAVING count > 1;
```

Оба запроса должны вернуть пустой результат.

### 2. Создать новый заказ

1. Создать заказ в b5-admin
2. Проверить таблицу `agent_bonuses`:
```sql
SELECT * FROM agent_bonuses WHERE order_id = '<order_id>';
```
3. Должна быть только одна запись

### 3. Проверить в b5-agent

1. Открыть страницу финансов
2. Проверить, что каждый заказ отображается только один раз

## Предотвращение дубликатов в будущем

### Вариант 1: Уникальный индекс (рекомендуется)

Добавить уникальный индекс на поля `order_id` и `contract_id` в таблице `agent_bonuses`:

```sql
-- Для заказов
CREATE UNIQUE INDEX idx_agent_bonuses_order_id 
ON agent_bonuses(order_id) 
WHERE order_id IS NOT NULL;

-- Для договоров
CREATE UNIQUE INDEX idx_agent_bonuses_contract_id 
ON agent_bonuses(contract_id) 
WHERE contract_id IS NOT NULL;
```

Это предотвратит создание дубликатов на уровне базы данных.

### Вариант 2: Проверка перед созданием

Добавить проверку в `BonusService::createBonusForOrder`:

```php
public function createBonusForOrder(Order $order): ?AgentBonus
{
    // Проверяем, не создан ли уже бонус для этого заказа
    $existingBonus = AgentBonus::where('order_id', $order->id)->first();
    if ($existingBonus) {
        \Log::warning("Bonus already exists for order", ['order_id' => $order->id]);
        return $existingBonus;
    }
    
    // ... остальной код создания бонуса
}
```

## Связанные файлы

- `app/GraphQL/Mutations/CreateOrder.php` - мутация создания заказа (исправлено)
- `app/Models/Order.php` - событие `created` (оставлено как есть)
- `app/Services/BonusService.php` - метод `createBonusForOrder`
- `check-duplicate-bonuses.php` - скрипт проверки дубликатов
- `remove-duplicate-bonuses.php` - скрипт удаления дубликатов

## Аналогичная проблема для договоров

Проверить, нет ли аналогичной проблемы в мутации `CreateContract`:

```bash
grep -n "createBonusForContract" app/GraphQL/Mutations/CreateContract.php
```

Если есть дублирующий вызов, удалить его аналогично.

## Дата исправления
22 декабря 2024
