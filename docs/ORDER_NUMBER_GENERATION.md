# Автоматическая генерация номера заказа

## Описание

Система автоматически генерирует уникальный номер заказа в формате **ORDER-ххххх-ххх**, если номер не указан при создании заказа.

## Формат номера

```
ORDER-12345-678
  │    │     │
  │    │     └─ 3 случайные цифры (100-999)
  │    └─────── 5 случайных цифр (10000-99999)
  └──────────── Префикс ORDER
```

## Примеры

- `ORDER-45678-234`
- `ORDER-12345-789`
- `ORDER-98765-432`

## Реализация

### Бэкенд (Laravel)

Генерация номера реализована в модели `Order` через событие `creating`:

```php
// app/Models/Order.php
protected static function boot()
{
    parent::boot();

    static::creating(function ($order) {
        if (empty($order->order_number)) {
            $order->order_number = self::generateOrderNumber();
        }
    });
}

public static function generateOrderNumber(): string
{
    do {
        $firstPart = str_pad((string)rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        $secondPart = str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);
        $orderNumber = "ORDER-{$firstPart}-{$secondPart}";
        $exists = self::where('order_number', $orderNumber)->exists();
    } while ($exists);

    return $orderNumber;
}
```

### GraphQL

Поле `order_number` теперь необязательное в `CreateOrderInput`:

```graphql
input CreateOrderInput {
    value: String!
    company_id: ID!
    project_id: ID!
    order_number: String  # Необязательное поле
    delivery_date: Date
    actual_delivery_date: Date
    is_active: Boolean = true
    is_urgent: Boolean = false
    positions: [CreateOrderPositionInput!]!
}
```

### Фронтенд (Svelte)

В форме добавления заказа поле "Номер заказа" имеет подсказку:

```svelte
<input
    type="text"
    id="order-number"
    bind:value={formData.order_number}
    placeholder="Автоматически (ORDER-ххххх-ххх)"
/>
<p class="mt-1 text-xs text-gray-500">
    Оставьте пустым для автоматической генерации
</p>
```

## Использование

### Создание заказа без номера (автоматическая генерация)

```javascript
const orderData = {
    value: "Поставка оборудования",
    company_id: "01JQWXYZ1234567890ABCDEFGH",
    project_id: "01JQWXYZ1234567890ABCDEFGK",
    // order_number не указан - будет сгенерирован автоматически
    delivery_date: "2025-02-15",
    is_active: true,
    is_urgent: false,
    positions: [...]
};

const newOrder = await createOrder(orderData);
console.log(newOrder.order_number); // "ORDER-12345-678"
```

### Создание заказа с указанным номером

```javascript
const orderData = {
    value: "Поставка оборудования",
    company_id: "01JQWXYZ1234567890ABCDEFGH",
    project_id: "01JQWXYZ1234567890ABCDEFGK",
    order_number: "CUSTOM-2025-001", // Указан вручную
    delivery_date: "2025-02-15",
    is_active: true,
    is_urgent: false,
    positions: [...]
};

const newOrder = await createOrder(orderData);
console.log(newOrder.order_number); // "CUSTOM-2025-001"
```

## Тестирование

### 1. Тест автоматической генерации

```bash
# GraphQL Playground
mutation {
  createOrder(input: {
    value: "Тестовый заказ"
    company_id: "01JQWXYZ1234567890ABCDEFGH"
    project_id: "01JQWXYZ1234567890ABCDEFGK"
    # order_number не указан
    positions: [{
      value: "Товар 1"
      article: "ART-001"
      price: 1000
      count: 1
    }]
  }) {
    id
    order_number
  }
}
```

Ожидаемый результат:
```json
{
  "data": {
    "createOrder": {
      "id": "01JQWXYZ...",
      "order_number": "ORDER-45678-234"
    }
  }
}
```

### 2. Тест уникальности

Создайте несколько заказов подряд и убедитесь, что все номера уникальны.

### 3. Тест ручного указания номера

```bash
mutation {
  createOrder(input: {
    value: "Тестовый заказ"
    company_id: "01JQWXYZ1234567890ABCDEFGH"
    project_id: "01JQWXYZ1234567890ABCDEFGK"
    order_number: "MANUAL-2025-001"
    positions: [...]
  }) {
    id
    order_number
  }
}
```

## Миграция существующих данных

Если в базе есть заказы без номера, выполните:

```bash
cd b5-api-2
php artisan tinker
```

```php
// Обновить все заказы без номера
Order::whereNull('order_number')->orWhere('order_number', '')->each(function ($order) {
    $order->order_number = Order::generateOrderNumber();
    $order->save();
});
```

## Очистка кэша

После изменений в GraphQL схеме:

```bash
cd b5-api-2
php artisan lighthouse:clear-cache
php artisan config:clear
php artisan cache:clear
```

## Примечания

- Номер генерируется только при создании заказа (событие `creating`)
- Если номер указан вручную, он должен быть уникальным
- Формат ORDER-ххххх-ххх обеспечивает ~900 миллионов уникальных комбинаций
- Генерация происходит на уровне модели, поэтому работает для всех способов создания заказа
