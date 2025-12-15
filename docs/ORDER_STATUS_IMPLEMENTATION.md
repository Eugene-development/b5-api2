# Реализация статусов заказов - Backend

## Статус: ✅ ГОТОВО

**Дата:** 15 декабря 2024

## Описание

Backend для функции статусов заказов полностью реализован и готов к использованию.

## Реализованные компоненты

### 1. База данных

#### Таблица `order_statuses`
**Миграция:** `b5-db-2/database/migrations/2025_12_13_120000_create_order_statuses_table.php`

**Структура:**
```sql
- id (ULID, PRIMARY KEY)
- value (VARCHAR) - отображаемое название
- slug (VARCHAR, UNIQUE) - уникальный код
- description (TEXT, NULLABLE) - описание
- color (VARCHAR(7), NULLABLE) - HEX цвет
- icon (VARCHAR, NULLABLE) - название иконки
- sort_order (INT) - порядок сортировки
- is_default (BOOLEAN) - статус по
 умолactive (BOOLEAN) - активность
- created_at, updated_at (TIMESTAMPS)
```

**Начальные данные:**
1. **Сформирован** (`formed`) - #3B82F6 - по умолчанию
2. **Доставлен** (`delivered`) - #22C55E
3. **Возврат** (`returned`) - #EF4444

#### Поле в таблице `orders`
**Миграция:** `b5-db-2/database/migrations/2025_12_13_120003_add_status_id_to_orders_table.php`

```sql
ALTER TABLE orders 
ADD COLUMN status_id VARCHAR(26) NULL,
ADD CONSTRAINT fk_orders_status_id 
    FOREIGN KEY (status_id) 
    REFERENCES order_statuses(id) 
    ON DELETE SET NULL;
```

### 2. Модели

#### OrderStatus
**Файл:** `b5-api-2/app/Models/OrderStatus.php`

**Особенности:**
- Использует ULID как первичный ключ
- Связь `orders()` - HasMany
- Метод `getDefault()` - получение статуса по умолчанию
- Scope `active()` - фильтрация активных статусов с сортировкой

**Fillable поля:**
```php
[
    'value',
    'slug',
    'description',
    'color',
    'icon',
    'sort_order',
    'is_default',
    'is_active',
]
```

#### Order (обновлена)
**Файл:** `b5-api-2/app/Models/Order.php`

**Добавлено:**
- Поле `status_id` в `$fillable`
- Метод `status()` - BelongsTo связь с OrderStatus

### 3. GraphQL Schema

**Файл:** `b5-api-2/graphql/order.graphql`

#### Тип OrderStatus
```graphql
type OrderStatus {
    id: ID!
    value: String!
    slug: String!
    description: String
    color: String
    icon: String
    sort_order: Int!
    is_default: Boolean!
    is_active: Boolean!
}
```

#### Обновлённый тип Order
```graphql
type Order {
    # ... существующие поля
    status_id: ID
    status: OrderStatus @belongsTo
}
```

#### Query
```graphql
extend type Query {
    "Get all order statuses"
    orderStatuses: [OrderStatus!]! 
        @all(model: "App\\Models\\OrderStatus", scopes: ["active"])
}
```

#### Mutation
```graphql
extend type Mutation {
    "Update order status"
    updateOrderStatus(order_id: ID!, status_slug: String!): Order!
        @field(resolver: "App\\GraphQL\\Mutations\\UpdateOrderStatus")
}
```

### 4. Resolver

**Файл:** `b5-api-2/app/GraphQL/Mutations/UpdateOrderStatus.php`

**Функциональность:**
- Находит заказ по ID
- Находит статус по slug (только активные)
- Обновляет `status_id` в заказе
- Загружает все связи (project, company, status, partnerPaymentStatus, agentBonus)
- Вызывает `BonusService::handleOrderStatusChange()` для обновления статуса бонуса
- Логирует изменения

**Обработка ошибок:**
- `ModelNotFoundException` если заказ не найден
- `ModelNotFoundException` если статус не найден или неактивен

## API Endpoints

### 1. Получение списка статусов

**Request:**
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

**Response:**
```json
{
  "data": {
    "orderStatuses": [
      {
        "id": "01JFEXAMPLE123456789ABCD",
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
        "id": "01JFEXAMPLE123456789ABCE",
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
        "id": "01JFEXAMPLE123456789ABCF",
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

### 2. Обновление статуса заказа

**Request:**
```graphql
mutation UpdateOrderStatus($orderId: ID!, $statusSlug: String!) {
  updateOrderStatus(order_id: $orderId, status_slug: $statusSlug) {
    id
    status_id
    status {
      id
      value
      slug
      color
      sort_order
    }
    order_number
    value
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

**Response:**
```json
{
  "data": {
    "updateOrderStatus": {
      "id": "01JFEXAMPLE123456789ABCG",
      "status_id": "01JFEXAMPLE123456789ABCE",
      "status": {
        "id": "01JFEXAMPLE123456789ABCE",
        "value": "Доставлен",
        "slug": "delivered",
        "color": "#22C55E",
        "sort_order": 2
      },
      "order_number": "ORDER-12345-678",
      "value": "Заказ материалов"
    }
  }
}
```

### 3. Получение заказов со статусами

**Request:**
```graphql
query GetOrders($first: Int!, $page: Int!) {
  orders(first: $first, page: $page) {
    data {
      id
      order_number
      value
      status_id
      status {
        id
        value
        slug
        color
      }
      # ... другие поля
    }
  }
}
```

## Интеграция с BonusService

При изменении статуса заказа автоматически вызывается:
```php
$bonusService->handleOrderStatusChange($order, $statusSlug);
```

Это позволяет обновлять статус связанных бонусов агента в зависимости от статуса заказа.

## Логирование

Все изменения статусов логируются:
```php
Log::info('UpdateOrderStatus: Success', [
    'order_id' => $order->id,
    'new_status' => $status->value,
]);
```

## Безопасность

- Все endpoints защищены `@guard` директивой (требуется аутентификация)
- Обновление статуса доступно только для активных статусов
- Используется `findOrFail()` для безопасного поиска записей
- Валидация slug статуса перед обновлением

## Тестирование

### Проверка получения статусов
```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "query": "query { orderStatuses { id value slug color sort_order } }"
  }'
```

### Проверка обновления статуса
```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "query": "mutation($orderId: ID!, $statusSlug: String!) { updateOrderStatus(order_id: $orderId, status_slug: $statusSlug) { id status { value slug color } } }",
    "variables": {
      "orderId": "YOUR_ORDER_ID",
      "statusSlug": "delivered"
    }
  }'
```

## Добавление новых статусов

Для добавления новых статусов используйте SQL:

```sql
INSERT INTO order_statuses (id, value, slug, description, color, sort_order, is_default, is_active, created_at, updated_at)
VALUES (
    'NEW_ULID_HERE',
    'Название статуса',
    'status_slug',
    'Описание статуса',
    '#HEXCOLOR',
    4,
    false,
    true,
    NOW(),
    NOW()
);
```

Или через Laravel Tinker:
```php
use App\Models\OrderStatus;
use Illuminate\Support\Str;

OrderStatus::create([
    'id' => Str::ulid()->toString(),
    'value' => 'В обработке',
    'slug' => 'processing',
    'description' => 'Заказ находится в обработке',
    'color' => '#F59E0B',
    'sort_order' => 4,
    'is_default' => false,
    'is_active' => true,
]);
```

## Связанные файлы

### Backend
- `b5-api-2/app/Models/OrderStatus.php` - модель статуса
- `b5-api-2/app/Models/Order.php` - модель заказа
- `b5-api-2/app/GraphQL/Mutations/UpdateOrderStatus.php` - мутация обновления
- `b5-api-2/graphql/order.graphql` - GraphQL схема

### Database
- `b5-db-2/database/migrations/2025_12_13_120000_create_order_statuses_table.php` - создание таблицы
- `b5-db-2/database/migrations/2025_12_13_120003_add_status_id_to_orders_table.php` - добавление поля

### Frontend
- `b5-admin/src/lib/components/business-processes/order/OrderStatusBadge.svelte`
- `b5-admin/src/lib/components/business-processes/order/OrderTable.svelte`
- `b5-admin/src/routes/(protected)/(business-processes)/order/+page.svelte`
- `b5-admin/src/lib/api/orders.js`

## Статус готовности

| Компонент | Статус |
|-----------|--------|
| База данных | ✅ Готово |
| Модели | ✅ Готово |
| GraphQL Schema | ✅ Готово |
| Resolvers | ✅ Готово |
| Frontend | ✅ Готово |
| Документация | ✅ Готово |

## Примечания

- Используется ULID вместо auto-increment ID для лучшей масштабируемости
- Статусы сортируются по полю `sort_order`
- Неактивные статусы не возвращаются в query `orderStatuses`
- При удалении статуса связанные заказы получают NULL в `status_id` (ON DELETE SET NULL)
- Существующие заказы автоматически получили статус по умолчанию при миграции
чанию
