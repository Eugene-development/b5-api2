# Руководство по тестированию статусов заказов

## Быстрая проверка

### 1. Проверка наличия статусов в БД

```bash
cd b5-api-2
php artisan tinker
```

```php
// В tinker
use App\Models\OrderStatus;

// Получить все статусы
$statuses = OrderStatus::all();
echo "Найдено статусов: " . $statuses->count() . "\n";
$statuses->each(function($s) {
    echo "- {$s->value} ({$s->slug}) - {$s->color}\n";
});

// Получить только активные
$active = OrderStatus::active()->get();
echo "\nАктивных статусов: " . $active->count() . "\n";

// Получить статус по умолчанию
$default = OrderStatus::getDefault();
echo "\nСтатус по умолчанию: " . ($default ? $default->value : 'не найден') . "\n";
```

### 2. Проверка связи Order -> OrderStatus

```php
// В tinker
use App\Models\Order;

// Получить первый заказ
$order = Order::first();

if ($order) {
    echo "Заказ: {$order->order_number}\n";
    echo "Статус ID: " . ($order->status_id ?? 'не установлен') . "\n";
    
    if ($order->status) {
        echo "Статус: {$order->status->value} ({$order->status->slug})\n";
        echo "Цвет: {$order->status->color}\n";
    } else {
        echo "Статус не привязан\n";
    }
} else {
    echo "Заказы не найдены\n";
}
```

### 3. Тестирование GraphQL Query (получение статусов)

**Через curl:**
```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "query": "query { orderStatuses { id value slug color sort_order is_default is_active } }"
  }' | jq
```

**Через GraphQL Playground (если установлен):**
```graphql
query GetOrderStatuses {
  orderStatuses {
    id
    value
    slug
    description
    color
    icon
    sort_order
    is_default
    is_active
  }
}
```

**Ожидаемый результат:**
```json
{
  "data": {
    "orderStatuses": [
      {
        "id": "01JFEXAMPLE...",
        "value": "Сформирован",
        "slug": "formed",
        "description": "Заказ сформирован и ожидает обработки",
        "color": "#3B82F6",
        "icon": null,
        "sort_order": 1,
        "is_default": true,
        "is_active": true
      },
      {
        "id": "01JFEXAMPLE...",
        "value": "Доставлен",
        "slug": "delivered",
        "description": "Заказ успешно доставлен",
        "color": "#22C55E",
        "icon": null,
        "sort_order": 2,
        "is_default": false,
        "is_active": true
      },
      {
        "id": "01JFEXAMPLE...",
        "value": "Возврат",
        "slug": "returned",
        "description": "Заказ возвращён",
        "color": "#EF4444",
        "icon": null,
        "sort_order": 3,
        "is_default": false,
        "is_active": true
      }
    ]
  }
}
```

### 4. Тестирование GraphQL Mutation (обновление статуса)

**Шаг 1: Получить ID заказа**
```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "query": "query { orders(first: 1) { data { id order_number status { value slug } } } }"
  }' | jq
```

**Шаг 2: Обновить статус заказа**
```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "query": "mutation($orderId: ID!, $statusSlug: String!) { updateOrderStatus(order_id: $orderId, status_slug: $statusSlug) { id order_number status { id value slug color } } }",
    "variables": {
      "orderId": "YOUR_ORDER_ID_HERE",
      "statusSlug": "delivered"
    }
  }' | jq
```

**Через GraphQL Playground:**
```graphql
mutation UpdateOrderStatus($orderId: ID!, $statusSlug: String!) {
  updateOrderStatus(order_id: $orderId, status_slug: $statusSlug) {
    id
    order_number
    value
    status_id
    status {
      id
      value
      slug
      color
      sort_order
    }
  }
}
```

**Variables:**
```json
{
  "orderId": "01JFEXAMPLE123456789ABCG",
  "statusSlug": "delivered"
}
```

**Ожидаемый результат:**
```json
{
  "data": {
    "updateOrderStatus": {
      "id": "01JFEXAMPLE123456789ABCG",
      "order_number": "ORDER-12345-678",
      "value": "Заказ материалов",
      "status_id": "01JFEXAMPLE123456789ABCE",
      "status": {
        "id": "01JFEXAMPLE123456789ABCE",
        "value": "Доставлен",
        "slug": "delivered",
        "color": "#22C55E",
        "sort_order": 2
      }
    }
  }
}
```

### 5. Проверка получения заказов со статусами

```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "query": "query { orders(first: 5, page: 1) { data { id order_number value status_id status { value slug color } } } }"
  }' | jq
```

## Тестирование через Tinker

### Обновление статуса заказа

```php
use App\Models\Order;
use App\Models\OrderStatus;

// Получить заказ
$order = Order::first();

// Получить статус "Доставлен"
$deliveredStatus = OrderStatus::where('slug', 'delivered')->first();

// Обновить статус
$order->status_id = $deliveredStatus->id;
$order->save();

// Проверить
$order->load('status');
echo "Новый статус: {$order->status->value}\n";
```

### Создание нового статуса

```php
use App\Models\OrderStatus;
use Illuminate\Support\Str;

$newStatus = OrderStatus::create([
    'id' => Str::ulid()->toString(),
    'value' => 'В обработке',
    'slug' => 'processing',
    'description' => 'Заказ находится в обработке',
    'color' => '#F59E0B',
    'sort_order' => 4,
    'is_default' => false,
    'is_active' => true,
]);

echo "Создан статус: {$newStatus->value} ({$newStatus->slug})\n";
```

## Проверка логов

```bash
# Просмотр логов обновления статусов
tail -f b5-api-2/storage/logs/laravel.log | grep "UpdateOrderStatus"
```

## Тестовые сценарии

### Сценарий 1: Изменение статуса через UI

1. Откройте страницу `/order` в браузере
2. Найдите любой заказ в таблице
3. Кликните на текущий статус в столбце "СТАТУС"
4. Выберите новый статус из выпадающего списка
5. Проверьте, что:
   - Статус изменился
   - Появилось уведомление об успехе
   - В логах появилась запись об изменении

### Сценарий 2: Проверка фильтрации активных статусов

```php
// В tinker
use App\Models\OrderStatus;

// Деактивировать статус
$status = OrderStatus::where('slug', 'returned')->first();
$status->is_active = false;
$status->save();

// Проверить, что он не возвращается в query
$active = OrderStatus::active()->get();
echo "Активных статусов: " . $active->count() . "\n";
// Должно быть на 1 меньше

// Активировать обратно
$status->is_active = true;
$status->save();
```

### Сценарий 3: Проверка статуса по умолчанию

```php
// В tinker
use App\Models\Order;
use App\Models\OrderStatus;

// Создать новый заказ без указания статуса
$order = Order::create([
    'value' => 'Тестовый заказ',
    'company_id' => 'SOME_COMPANY_ID',
    'project_id' => 'SOME_PROJECT_ID',
    'order_number' => 'TEST-' . rand(10000, 99999),
    'is_active' => true,
    'is_urgent' => false,
]);

// Проверить, что статус не установлен автоматически
// (в текущей реализации статус устанавливается вручную)
echo "Статус ID: " . ($order->status_id ?? 'не установлен') . "\n";
```

## Возможные ошибки и решения

### Ошибка: "Unauthenticated"
**Причина:** Отсутствует или невалидный JWT токен  
**Решение:** Получите новый токен через `/api/login`

### Ошибка: "No query results for model [App\Models\OrderStatus]"
**Причина:** Статус с указанным slug не найден или неактивен  
**Решение:** Проверьте существование статуса и его активность

### Ошибка: "No query results for model [App\Models\Order]"
**Причина:** Заказ с указанным ID не найден  
**Решение:** Проверьте правильность ID заказа

### Ошибка: "SQLSTATE[23000]: Integrity constraint violation"
**Причина:** Попытка установить несуществующий status_id  
**Решение:** Убедитесь, что статус существует в таблице order_statuses

## Проверка производительности

### Тест N+1 проблемы

```php
// В tinker
use App\Models\Order;
use Illuminate\Support\Facades\DB;

// Включить логирование запросов
DB::enableQueryLog();

// Получить заказы со статусами
$orders = Order::with('status')->limit(10)->get();

// Вывести количество запросов
$queries = DB::getQueryLog();
echo "Выполнено запросов: " . count($queries) . "\n";
// Должно быть 2 запроса (1 для orders, 1 для statuses)

// Вывести запросы
foreach ($queries as $query) {
    echo $query['query'] . "\n";
}
```

## Чеклист тестирования

- [ ] Статусы существуют в БД
- [ ] Query `orderStatuses` возвращает список статусов
- [ ] Статусы отсортированы по `sort_order`
- [ ] Неактивные статусы не возвращаются
- [ ] Mutation `updateOrderStatus` успешно обновляет статус
- [ ] Обновление статуса логируется
- [ ] Связь Order -> OrderStatus работает
- [ ] Query `orders` возвращает заказы со статусами
- [ ] Frontend корректно отображает статусы
- [ ] Изменение статуса через UI работает
- [ ] Toast уведомления отображаются
- [ ] Нет N+1 проблемы при загрузке заказов со статусами

## Дополнительные команды

### Очистка кэша GraphQL
```bash
cd b5-api-2
php artisan lighthouse:clear-cache
```

### Проверка GraphQL схемы
```bash
cd b5-api-2
php artisan lighthouse:print-schema | grep -A 20 "type OrderStatus"
```

### Пересоздание БД (ОСТОРОЖНО!)
```bash
cd b5-api-2
php artisan migrate:fresh --seed
```

## Контакты

При возникновении проблем проверьте:
1. Логи Laravel: `b5-api-2/storage/logs/laravel.log`
2. Логи Nginx/Apache
3. Консоль браузера (для frontend ошибок)
