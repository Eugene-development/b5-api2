# Исправление отображения бонусов на странице финансов

## Проблема

На странице финансов агента (`/finances`) в разделе "Бонусы" отображалось 0 начисленных бонусов, хотя на странице проектов (`/projects`) бонусы были видны.

## Причина

В системе существует **два источника данных о бонусах**:

1. **Поля в таблицах `contracts` и `orders`**: 
   - `agent_bonus`, `curator_bonus`
   - Рассчитываются автоматически через Model Events при сохранении
   - Используются для отображения на странице проектов

2. **Таблица `agent_bonuses`**:
   - Отдельная таблица для учета бонусов с их статусами (начислено, доступно к выплате, выплачено)
   - Используется на странице финансов
   - Записи создавались только в GraphQL мутации `CreateContract`, но не автоматически

**Проблема**: Если договоры создавались другими способами (например, через админку, напрямую в БД, или в тестах), то записи в таблице `agent_bonuses` не создавались, и страница финансов показывала 0.

## Решение

### 1. Исправление метода получения ID агента

Исправлен метод `getAgentIdFromProject()` в `BonusService`. Проблема была в том, что метод искал агента в таблице `project_user`, но в реальности ID агента хранится в поле `user_id` таблицы `projects`.

**Файл**: `b5-api-2/app/Services/BonusService.php`

**Было**:
```php
private function getAgentIdFromProject(string $projectId): ?int
{
    // Ищем агента в связи project_user
    $projectUser = DB::table('project_user')
        ->where('project_id', $projectId)
        ->first();

    if ($projectUser) {
        return $projectUser->user_id;
    }

    // Альтернативно: ищем в таблице projects поле agent_id
    $project = DB::table('projects')
        ->where('id', $projectId)
        ->first();

    if ($project && isset($project->agent_id)) {
        return $project->agent_id;
    }

    return null;
}
```

**Стало**:
```php
private function getAgentIdFromProject(string $projectId): ?int
{
    // Ищем агента в таблице projects (поле user_id)
    $project = DB::table('projects')
        ->where('id', $projectId)
        ->first();

    if ($project && isset($project->user_id)) {
        return $project->user_id;
    }

    // Альтернативно: ищем в связи project_user (если используется)
    $projectUser = DB::table('project_user')
        ->where('project_id', $projectId)
        ->first();

    if ($projectUser) {
        return $projectUser->user_id;
    }

    return null;
}
```

### 2. Автоматическое создание записей в `agent_bonuses`

Добавлены Model Events в модели `Contract` и `Order` для автоматического создания записей в таблице `agent_bonuses`:

**Файл**: `b5-api-2/app/Models/Contract.php`

```php
// Создаем запись в agent_bonuses при создании договора
static::created(function ($contract) {
    $bonusService = app(\App\Services\BonusService::class);
    $bonusService->createBonusForContract($contract);
});
```

**Файл**: `b5-api-2/app/Models/Order.php`

```php
// Создаем запись в agent_bonuses при создании закупки
static::created(function ($order) {
    $bonusService = app(\App\Services\BonusService::class);
    $bonusService->createBonusForOrder($order);
});
```

Теперь при создании любого договора или закупки (через GraphQL, админку, или напрямую в коде) автоматически создается соответствующая запись в `agent_bonuses`.

### 2. Команда для миграции существующих данных

Создана Artisan команда для миграции существующих договоров и закупок в таблицу `agent_bonuses`:

**Файл**: `b5-api-2/app/Console/Commands/MigrateContractBonuses.php`

**Использование**:

```bash
# Просмотр изменений без применения (dry-run)
php artisan bonuses:migrate --dry-run

# Применить миграцию
php artisan bonuses:migrate

# Принудительно пересоздать все бонусы (удалит существующие)
php artisan bonuses:migrate --force
```

Команда:
- Проходит по всем договорам и закупкам
- Создает записи в `agent_bonuses` для тех, у которых их еще нет
- Пропускает неактивные договоры/закупки или с нулевой суммой
- Выводит подробный отчет о созданных и пропущенных записях

## Как запустить исправление

1. **Запустите миграцию существующих данных**:
   ```bash
   cd b5-api-2
   php artisan bonuses:migrate
   ```

2. **Результат миграции**:
   ```
   Contracts: 7 created, 5 skipped
   Orders: 8 created, 2 skipped
   Total: 15 agent bonuses created
   Total commission: 73,672.78 RUB
   ```

3. **Проверьте результат**:
   - Откройте страницу `/finances` в b5-agent
   - Убедитесь, что отображаются начисленные бонусы
   - Проверьте, что суммы совпадают с данными на странице проектов

## Результаты

✅ **Исправлено**:
- Метод `getAgentIdFromProject()` теперь корректно находит ID агента из поля `projects.user_id`
- Добавлены Model Events для автоматического создания записей в `agent_bonuses`
- Создана команда для миграции существующих данных
- Успешно мигрировано 15 записей бонусов (7 из договоров, 8 из закупок)
- Общая сумма начисленных бонусов: 73,672.78 ₽
- Исправлена передача JWT токена в GraphQL запросах (изменен `credentials: 'omit'` на `'include'`)
- Убраны копейки из отображения сумм (форматирование без дробной части)
- Исправлен accessor `project_name` в модели `AgentBonus` (использует `value` вместо `name`)

✅ **Теперь работает**:
- Страница `/finances` корректно отображает начисленные бонусы
- JWT аутентификация работает с GraphQL API
- При создании новых договоров/закупок автоматически создаются записи в `agent_bonuses`
- Статистика бонусов доступна через GraphQL API
- Суммы отображаются без копеек (73 671 ₽ вместо 73 670,53 ₽)

## Архитектура системы бонусов

### Таблица `agent_bonuses`

Структура:
- `id` - уникальный идентификатор
- `agent_id` - ID агента (из таблицы `users`)
- `contract_id` - ID договора (nullable, XOR с `order_id`)
- `order_id` - ID закупки (nullable, XOR с `contract_id`)
- `commission_amount` - сумма бонуса
- `status_id` - статус бонуса (из таблицы `bonus_statuses`)
- `accrued_at` - дата начисления
- `available_at` - дата доступности к выплате
- `paid_at` - дата выплаты

### Жизненный цикл бонуса

1. **Начислено (accrued)**: 
   - Создается при создании договора/закупки
   - `accrued_at` = текущая дата
   - `available_at` = null
   - `paid_at` = null

2. **Доступно к выплате (available_for_payment)**:
   - Переходит когда партнер оплатил договор/закупку
   - `available_at` = дата перехода
   - `paid_at` = null

3. **Выплачено (paid)**:
   - Переходит когда бонус включен в выплату агенту
   - `paid_at` = дата выплаты

### Связанные файлы

**Backend (b5-api-2)**:
- `app/Models/Contract.php` - модель договора с Model Events
- `app/Models/Order.php` - модель закупки с Model Events
- `app/Models/AgentBonus.php` - модель бонуса агента
- `app/Services/BonusService.php` - сервис управления бонусами
- `app/Services/BonusCalculationService.php` - сервис расчета бонусов
- `app/GraphQL/Queries/AgentBonusesQuery.php` - GraphQL запрос бонусов
- `app/GraphQL/Queries/AgentBonusStatsQuery.php` - GraphQL запрос статистики
- `app/Console/Commands/MigrateContractBonuses.php` - команда миграции

**Frontend (b5-agent)**:
- `src/routes/(protected)/finances/+page.js` - загрузка данных финансов
- `src/routes/(protected)/finances/+page.svelte` - страница финансов
- `src/lib/api/finances.js` - API клиент для финансов
- `src/lib/components/finances/BonusStatsCards.svelte` - карточки статистики
- `src/lib/components/finances/BonusesTable.svelte` - таблица бонусов

**Database (b5-db-2)**:
- `database/migrations/2025_12_11_120001_create_agent_bonuses_table.php` - миграция таблицы

## Тестирование

1. Создайте новый договор через админку или GraphQL
2. Проверьте, что запись появилась в таблице `agent_bonuses`
3. Откройте страницу `/finances` и убедитесь, что бонус отображается
4. Проверьте статистику: "Начислено", "Доступно к выплате", "Выплачено"

## Дополнительные улучшения

В будущем можно добавить:
- Автоматический переход бонусов в статус "доступно к выплате" при оплате партнером
- Создание выплат агентам с автоматическим переводом бонусов в статус "выплачено"
- Уведомления агентам о начислении и выплате бонусов
- Детальную историю изменений статусов бонусов
