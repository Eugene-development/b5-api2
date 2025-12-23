# Исправление: Установка статуса по умолчанию при создании заказа

## Проблема

При создании нового заказа в сервисе b5-admin статус заказа отображался как "Не указано", хотя должен был быть "Сформирован".

## Причина

В мутации `CreateOrder` использовался оператор `?->` (null-safe operator) при установке `status_id`:

```php
'status_id' => $defaultStatus?->id,
```

Если метод `OrderStatus::getDefault()` возвращал `null`, то `status_id` устанавливался в `null`, что приводило к отображению "Не указано".

## Решение

Обновлена логика в `app/GraphQL/Mutations/CreateOrder.php`:

1. Сначала пытаемся получить дефолтный статус через `OrderStatus::getDefault()`
2. Если дефолтный статус не найден, получаем первый активный статус
3. Если активных статусов нет вообще, выбрасываем ошибку
4. Гарантированно устанавливаем `status_id` (без null-safe оператора)

### Код до исправления:

```php
// Get default order status
$defaultStatus = OrderStatus::getDefault();

// Create the order
$order = Order::create([
    // ...
    'status_id' => $defaultStatus?->id, // Может быть null
]);
```

### Код после исправления:

```php
// Get default order status
$defaultStatus = OrderStatus::getDefault();

// If no default status found, get the first active status or create error
if (!$defaultStatus) {
    $defaultStatus = OrderStatus::where('is_active', true)
        ->orderBy('sort_order')
        ->first();
        
    if (!$defaultStatus) {
        throw new Error('No active order status found in the system');
    }
}

// Create the order
$order = Order::create([
    // ...
    'status_id' => $defaultStatus->id, // Гарантированно не null
]);
```

## Проверка

### 1. Проверить наличие дефолтного статуса в БД

```sql
SELECT id, value, slug, is_default, is_active
FROM order_statuses
WHERE is_default = true AND is_active = true;
```

Должен вернуть статус "Сформирован" (slug: `formed`).

### 2. Создать новый заказ в b5-admin

1. Открыть форму создания заказа
2. Заполнить обязательные поля
3. Проставить чекбокс "Активный"
4. Сохранить заказ

**Ожидаемый результат**: Статус заказа должен быть "Сформирован", а не "Не указано".

### 3. Проверить через GraphQL

```graphql
mutation {
  createOrder(input: {
    value: "Тестовый заказ"
    company_id: "01..."
    project_id: "01..."
    is_active: true
    positions: [{
      value: "Позиция 1"
      article: "ART-001"
      price: 1000
      count: 1
    }]
  }) {
    id
    order_number
    status {
      id
      value
      slug
    }
  }
}
```

Поле `status.value` должно быть "Сформирован".

## Связанные файлы

- `app/GraphQL/Mutations/CreateOrder.php` - мутация создания заказа
- `app/Models/OrderStatus.php` - модель статуса заказа
- `database/migrations/2025_12_13_120000_create_order_statuses_table.php` - миграция со статусами

## Дополнительные улучшения

Если проблема повторяется, можно также:

1. Добавить валидацию на уровне базы данных (NOT NULL для status_id)
2. Добавить проверку в модель Order (событие `creating`)
3. Добавить логирование, если дефолтный статус не найден

## Дата исправления
22 декабря 2024
