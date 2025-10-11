# Orders API Documentation

## Обзор

API для управления заказами и их позициями. Реализовано с использованием GraphQL.

## Модели

### Order (Заказ)

-   `id` - ULID уникальный идентификатор
-   `value` - Описание/наименование заказа
-   `company_id` - ID компании-заказчика
-   `project_id` - ID проекта
-   `order_number` - Уникальный номер заказа (автоматически генерируется в формате ORDER-ххххх-ххх, если не указан)
-   `delivery_date` - Планируемая дата поставки
-   `actual_delivery_date` - Фактическая дата поставки
-   `is_active` - Активность заказа
-   `is_urgent` - Срочность заказа

### OrderPosition (Позиция заказа)

-   `id` - ULID уникальный идентификатор
-   `order_id` - ID заказа
-   `value` - Наименование товара/услуги
-   `article` - Артикул
-   `price` - Цена за единицу
-   `count` - Количество
-   `total_price` - Общая стоимость (вычисляемое поле)
-   `supplier` - Поставщик
-   `expected_delivery_date` - Ожидаемая дата поставки
-   `actual_delivery_date` - Фактическая дата поставки
-   Ког

## GraphQL Queries

### Получить все заказы

```graphql
query GetOrders($first: Int!, $page: Int!) {
    orders(first: $first, page: $page) {
        data {
            id
            value
            company_id
            project_id
            order_number
            delivery_date
            actual_delivery_date
            is_active
            is_urgent
            created_at
            updated_at
            company {
                id
                name
                legal_name
            }
            project {
                id
                value
            }
            positions {
                id
                value
                article
                price
                count
                total_price
                supplier
                expected_delivery_date
                actual_delivery_date
                is_active
                is_urgent
            }
        }
        paginatorInfo {
            count
            currentPage
            total
        }
    }
}
```

### Получить один заказ

```graphql
query GetOrder($id: ID!) {
    order(id: $id) {
        id
        value
        order_number
        company {
            name
        }
        project {
            value
        }
        positions {
            value
            article
            price
            count
            total_price
        }
    }
}
```

## GraphQL Mutations

### Создать заказ с позициями

```graphql
mutation CreateOrder($input: CreateOrderInput!) {
    createOrder(input: $input) {
        id
        value
        order_number
        company_id
        project_id
        delivery_date
        actual_delivery_date
        is_active
        is_urgent
        created_at
        updated_at
        positions {
            id
            value
            article
            price
            count
            total_price
            supplier
            expected_delivery_date
            is_active
            is_urgent
        }
    }
}
```

**Переменные:**

```json
{
    "input": {
        "value": "Поставка оборудования",
        "company_id": "01JQWXYZ1234567890ABCDEFGH",
        "project_id": "01JQWXYZ1234567890ABCDEFGK",
        "delivery_date": "2025-02-15",
        "is_active": true,
        "is_urgent": false,
        "positions": [
            {
                "value": "Компьютер Dell",
                "article": "DELL-PC-001",
                "price": 25000.0,
                "count": 5,
                "supplier": "ООО Техносервис",
                "expected_delivery_date": "2025-02-10",
                "is_active": true,
                "is_urgent": false
            },
            {
                "value": "Монитор Samsung",
                "article": "SAMS-MON-24",
                "price": 15000.0,
                "count": 5,
                "is_active": true,
                "is_urgent": false
            }
        ]
    }
}
```

### Обновить заказ

```graphql
mutation UpdateOrder($input: UpdateOrderInput!) {
    updateOrder(input: $input) {
        id
        value
        order_number
        is_active
        is_urgent
    }
}
```

### Удалить заказ

```graphql
mutation DeleteOrder($id: ID!) {
    deleteOrder(id: $id) {
        id
        order_number
    }
}
```

## Установка и настройка

### 1. Запустить миграции

```bash
cd b5-api-2
php artisan migrate
```

### 2. Очистить кэш GraphQL

```bash
php artisan lighthouse:clear-cache
```

### 3. Проверить схему GraphQL

```bash
php artisan lighthouse:print-schema
```

## Связи между таблицами

```
orders (1) -----> (N) order_positions
  |                      |
  |                      |
  v                      v
companies            orders
  |
  v
projects
```

## Валидация

-   Заказ должен иметь хотя бы одну позицию
-   Номер заказа должен быть уникальным (если указан вручную)
-   Номер заказа автоматически генерируется в формате ORDER-ххххх-ххх, если не указан
-   Все обязательные поля должны быть заполнены
-   Цена и количество должны быть положительными числами

## Обработка ошибок

API возвращает ошибки в стандартном формате GraphQL:

```json
{
    "errors": [
        {
            "message": "Order with this order number already exists",
            "extensions": {
                "category": "validation"
            }
        }
    ]
}
```

## Примеры использования

### Создание заказа из фронтенда (JavaScript)

```javascript
import { createOrder } from "$lib/api/orders.js";

const orderData = {
    value: "Поставка оборудования",
    company_id: "01JQWXYZ1234567890ABCDEFGH",
    project_id: "01JQWXYZ1234567890ABCDEFGK",
    // order_number: "ORD-2025-001", // Необязательно - будет сгенерирован автоматически
    delivery_date: "2025-02-15",
    is_active: true,
    is_urgent: false,
    positions: [
        {
            value: "Компьютер Dell",
            article: "DELL-PC-001",
            price: 25000.0,
            count: 5,
            supplier: "ООО Техносервис",
            is_active: true,
            is_urgent: false,
        },
    ],
};

const newOrder = await createOrder(orderData);
console.log("Created order:", newOrder);
// Вывод: { id: "...", order_number: "ORDER-12345-678", ... }
```

## Тестирование

### Использование GraphQL Playground

1. Откройте `http://localhost:8000/graphql-playground`
2. Используйте примеры запросов выше
3. Проверьте результаты

### Использование curl

```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -d '{
    "query": "query { orders(first: 10) { data { id order_number } } }"
  }'
```
