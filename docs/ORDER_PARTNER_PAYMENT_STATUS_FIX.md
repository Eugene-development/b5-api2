# Исправление поля partner_payment_status_id в Order

## Проблема

При переходе на страницу закупок возникала ошибка:
```
Cannot query field "partner_payment_status_id" on type "Order"
```

## Причина

Поле `partner_payment_status_id` было добавлено в базу данных через миграцию, но отсутствовало в GraphQL схеме Order.

## Решение

### 1. Добавлено поле в GraphQL схему `order.graphql`

```graphql
type Order {
    ...
    "ID статуса оплаты партнёром"
    partner_payment_status_id: ID!
    "Статус оплаты партнёром"
    partnerPaymentStatus: PartnerPaymentStatus @belongsTo
    ...
}
```

### 2. Добавлено поле в fillable модели Order

```php
protected $fillable = [
    ...
    'partner_payment_status_id',
];
```

### 3. Обновлена мутация CreateOrder

Добавлено автоматическое установление статуса "pending" (id = 1) при создании закупки:

```php
$order = Order::create([
    ...
    'partner_payment_status_id' => 1, // pending по умолчанию
]);
```

## Связанные файлы

- `b5-api-2/graphql/order.graphql` - GraphQL схема Order
- `b5-api-2/app/Models/Order.php` - Модель Order
- `b5-api-2/app/GraphQL/Mutations/CreateOrder.php` - Мутация создания закупки
- `b5-db-2/database/migrations/2025_12_11_120004_add_partner_payment_status_to_contracts_and_orders.php` - Миграция

## Примечание

Поле `partner_payment_status_id` используется для отслеживания статуса оплаты партнёром. Возможные статусы:
- `pending` (id = 1) - Ожидает оплаты
- `paid` (id = 2) - Оплачено
- `cancelled` (id = 3) - Отменено

Отношение `partnerPaymentStatus()` уже было реализовано в модели Order.
