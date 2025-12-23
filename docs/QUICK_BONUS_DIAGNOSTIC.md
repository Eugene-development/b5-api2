# Быстрая диагностика проблем с бонусами

## Договор не отображается на странице финансов

### Причина
Договор в статусе "Обработка" (preparing) не должен отображаться на странице финансов.

### Решение
Изменить статус договора на "Заключён" (signed) в b5-admin.

### Проверка
```sql
SELECT c.id, c.contract_number, cs.value as status, cs.slug
FROM contracts c
LEFT JOIN contract_statuses cs ON c.status_id = cs.id
WHERE c.contract_number = 'DOC-XXXX-XXXX';
```

Если `slug = 'preparing'`, договор не будет отображаться.

---

## Заказ не отображается на странице финансов

### Возможные причины

1. **order_amount не указан или равен 0**
   ```sql
   SELECT id, order_number, order_amount, is_active
   FROM orders
   WHERE order_number = 'ORDER-XXXXX-XXX';
   ```
   
   Если `order_amount IS NULL` или `= 0`, бонус не создаётся.

2. **is_active = false**
   ```sql
   SELECT id, order_number, is_active
   FROM orders
   WHERE order_number = 'ORDER-XXXXX-XXX';
   ```
   
   Если `is_active = false`, бонус не создаётся.

3. **Бонус не создан**
   ```sql
   SELECT COUNT(*) as bonus_count
   FROM agent_bonuses
   WHERE order_id = '<order_id>';
   ```
   
   Если `bonus_count = 0`, бонус не был создан.

### Быстрое решение

```bash
# 1. Проверить заказ
cd b5-api-2
php test-order-bonus.php <order_id>

# 2. Если order_amount не указан, установить его
# В b5-admin или через SQL:
UPDATE orders SET order_amount = <сумма> WHERE id = '<order_id>';

# 3. Создать бонус вручную (если нужно)
php artisan tinker
>>> $order = App\Models\Order::find('<order_id>');
>>> $bonusService = app(\App\Services\BonusService::class);
>>> $bonus = $bonusService->createBonusForOrder($order);
>>> echo "Bonus created: " . ($bonus ? $bonus->id : 'failed');
```

---

## Бонус создан, но не отображается

### Проверка фильтрации

```sql
-- Проверить, что бонус существует
SELECT 
    ab.id,
    ab.order_id,
    ab.contract_id,
    ab.commission_amount,
    bs.code as status_code
FROM agent_bonuses ab
LEFT JOIN bonus_statuses bs ON ab.status_id = bs.id
WHERE ab.agent_id = <agent_id>;

-- Для договоров: проверить статус договора
SELECT 
    ab.id,
    c.contract_number,
    cs.slug as contract_status
FROM agent_bonuses ab
LEFT JOIN contracts c ON ab.contract_id = c.id
LEFT JOIN contract_statuses cs ON c.status_id = cs.id
WHERE ab.agent_id = <agent_id> AND ab.contract_id IS NOT NULL;
```

Если `contract_status = 'preparing'`, бонус не будет отображаться (это правильное поведение).

---

## Статистика бонусов неверная

### Проверка

```sql
-- Вручную посчитать статистику
SELECT 
    SUM(ab.commission_amount) as total_accrued,
    SUM(CASE WHEN bs.code = 'available_for_payment' THEN ab.commission_amount ELSE 0 END) as total_available,
    SUM(CASE WHEN bs.code = 'paid' THEN ab.commission_amount ELSE 0 END) as total_paid
FROM agent_bonuses ab
LEFT JOIN bonus_statuses bs ON ab.status_id = bs.id
LEFT JOIN contracts c ON ab.contract_id = c.id
LEFT JOIN contract_statuses cs ON c.status_id = cs.id
WHERE ab.agent_id = <agent_id>
  AND (
    -- Для договоров: исключаем статус "Обработка"
    (ab.contract_id IS NOT NULL AND cs.slug != 'preparing')
    -- Для заказов: показываем все
    OR ab.order_id IS NOT NULL
  );
```

Сравнить с результатом GraphQL запроса `agentBonusStats`.

---

## Полезные команды

### Пересчитать бонусы для всех заказов

```bash
php artisan tinker
>>> $orders = App\Models\Order::whereNull('order_amount')->orWhere('order_amount', 0)->get();
>>> foreach ($orders as $order) {
...     if ($order->positions->count() > 0) {
...         $total = $order->positions->sum('total_price');
...         $order->order_amount = $total;
...         $order->save();
...         echo "Updated order {$order->order_number}: {$total}\n";
...     }
... }
```

### Создать бонусы для заказов без бонусов

```bash
php artisan tinker
>>> $orders = App\Models\Order::whereDoesntHave('agentBonus')
...     ->where('is_active', true)
...     ->where('order_amount', '>', 0)
...     ->get();
>>> $bonusService = app(\App\Services\BonusService::class);
>>> foreach ($orders as $order) {
...     $bonus = $bonusService->createBonusForOrder($order);
...     echo "Created bonus for order {$order->order_number}: " . ($bonus ? $bonus->id : 'failed') . "\n";
... }
```

---

## Дата создания
22 декабря 2024
