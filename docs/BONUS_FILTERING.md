# Фильтрация бонусов по статусам

## Описание

Система фильтрации бонусов агентов и кураторов по статусам договоров и заказов.

## Логика фильтрации

### Бэкенд (b5-api-2)

Фильтрация происходит на уровне сервиса `BonusCalculationService`:

- **Договоры**: отображаются только со статусом **"Заключён"** (slug: `signed`)
- **Заказы**: отображаются только со статусом **"Сформирован"** (slug: `formed`)

### Расчёт бонусов

В `totalAgentBonus` и `totalCuratorBonus` учитываются только:
- Договоры со статусом "Заключён"
- Заказы со статусом "Сформирован"

### Фронтенд (b5-agent)

Фронтенд получает уже отфильтрованные данные и просто отображает их без дополнительной фильтрации.

## Файлы

### Бэкенд
- `b5-api-2/app/Services/BonusCalculationService.php` - логика фильтрации и расчёта
- `b5-api-2/graphql/project.graphql` - GraphQL схема
- `b5-api-2/app/Models/OrderStatus.php` - модель статусов заказов
- `b5-api-2/app/Models/ContractStatus.php` - модель статусов договоров

### Фронтенд
- `b5-agent/src/lib/components/BonusDetailsSection.svelte` - компонент отображения бонусов
- `b5-agent/src/lib/api/projects.js` - GraphQL запросы

### База данных
- `b5-db-2/database/migrations/2025_12_13_120000_create_order_statuses_table.php`
- `b5-db-2/database/migrations/2025_12_13_120001_create_contract_statuses_table.php`
- `b5-db-2/database/migrations/2025_12_13_120003_add_status_id_to_orders_table.php`
- `b5-db-2/database/migrations/2025_12_13_120004_add_status_id_to_contracts_table.php`

## Статусы

### Статусы заказов (order_statuses)
1. **Сформирован** (slug: `formed`) - учитывается в бонусах ✅
2. Доставлен (slug: `delivered`)
3. Возврат (slug: `returned`)

### Статусы договоров (contract_statuses)
1. Подготавливается (slug: `preparing`)
2. **Заключён** (slug: `signed`) - учитывается в бонусах ✅
3. Выполнен (slug: `completed`)
4. Рекламация (slug: `claim`)
5. Отказ (slug: `rejected`)
6. Расторгнут (slug: `terminated`)

## Примеры использования

### GraphQL запрос
```graphql
query GetProject($id: ID!) {
  project(id: $id) {
    bonusDetails {
      totalAgentBonus
      totalCuratorBonus
      contracts {
        id
        contract_number
        contract_amount
        agent_bonus
      }
      orders {
        id
        order_number
        order_amount
        agent_bonus
      }
    }
  }
}
```

Ответ будет содержать только договоры со статусом "Заключён" и заказы со статусом "Сформирован".

## Очистка кэша

После изменений в GraphQL схеме необходимо очистить кэш:

```bash
cd b5-api-2
php artisan lighthouse:clear-cache
php artisan config:clear
php artisan cache:clear
```
