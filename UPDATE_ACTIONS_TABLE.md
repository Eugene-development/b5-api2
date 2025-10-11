# Обновление таблицы actions - даты необязательные

## Изменения

Поля `start` и `end` теперь необязательные (nullable).

## Способ 1: Пересоздать таблицу (если нет важных данных)

```bash
cd b5-db-2

# Откатить миграцию
php artisan migrate:rollback --step=1

# Запустить заново
php artisan migrate
```

## Способ 2: Создать новую миграцию (если есть данные)

```bash
cd b5-db-2

# Создать миграцию
php artisan make:migration make_action_dates_nullable
```

Затем отредактируйте созданный файл:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->date('start')->nullable()->change();
            $table->date('end')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->date('start')->nullable(false)->change();
            $table->date('end')->nullable(false)->change();
        });
    }
};
```

Запустите миграцию:

```bash
php artisan migrate
```

## Способ 3: Прямой SQL (быстрый)

```bash
# Подключитесь к БД и выполните:
ALTER TABLE actions MODIFY start DATE NULL;
ALTER TABLE actions MODIFY end DATE NULL;
```

## После обновления

1. Очистите кэш:
```bash
cd b5-api-2
php artisan cache:clear
php artisan lighthouse:clear-cache
```

2. Перезапустите сервер

3. Проверьте в GraphQL Playground:
```graphql
mutation {
  createAction(input: {
    name: "Тестовая акция без дат"
    description: "Описание"
    company_id: "YOUR_COMPANY_ID"
    is_active: false
  }) {
    id
    name
    start
    end
  }
}
```

Ожидаемый результат:
```json
{
  "data": {
    "createAction": {
      "id": "...",
      "name": "Тестовая акция без дат",
      "start": null,
      "end": null
    }
  }
}
```

## Проверка на фронтенде

1. Откройте `/actions`
2. Нажмите "Добавить акцию"
3. Заполните только обязательные поля (название, описание, компания)
4. Оставьте даты пустыми
5. Сохраните

В таблице должно отображаться "Не указано" в колонке "Период".
