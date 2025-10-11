# Перезапуск API после изменений

## Что было исправлено:

1. ✅ Упрощена GraphQL схема `action.graphql`
2. ✅ Убраны сложные валидации
3. ✅ Добавлены явные указания модели
4. ✅ Исправлены API запросы во фронтенде
5. ✅ Убраны моковые данные из fallback

## Команды для перезапуска:

```bash
cd b5-api-2

# 1. Очистить все кэши
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan lighthouse:clear-cache

# 2. Пересоздать autoload
composer dump-autoload

# 3. Проверить миграции
php artisan migrate:status

# 4. Перезапустить сервер
# Остановите текущий сервер (Ctrl+C) и запустите снова:
php artisan serve
```

## Проверка работы:

### 1. Проверьте GraphQL Playground:
```
http://localhost:8000/graphql-playground
```

### 2. Выполните тестовый запрос:
```graphql
query {
  actions(first: 10) {
    data {
      id
      name
    }
    paginatorInfo {
      total
    }
  }
}
```

Ожидаемый результат:
```json
{
  "data": {
    "actions": {
      "data": [],
      "paginatorInfo": {
        "total": 0
      }
    }
  }
}
```

### 3. Проверьте компании:
```graphql
query {
  companies(first: 10) {
    data {
      id
      name
    }
    paginatorInfo {
      total
    }
  }
}
```

### 4. Если есть компании, создайте тестовую акцию:
```graphql
mutation {
  createAction(input: {
    name: "Тестовая акция"
    description: "Описание тестовой акции"
    start: "2025-02-01"
    end: "2025-02-28"
    company_id: "PASTE_COMPANY_ID_HERE"
    is_active: false
  }) {
    id
    name
    description
    start
    end
    is_active
    company {
      name
    }
  }
}
```

## Если всё работает:

Откройте фронтенд:
```
http://localhost:5173/actions
```

Страница должна:
- Загрузиться без ошибок
- Показать пустой список или список акций из БД
- Кнопка "Добавить акцию" должна работать
- При добавлении акция должна сохраниться в БД

## Возможные ошибки:

### "Internal server error"
- Проверьте логи: `storage/logs/laravel.log`
- Очистите кэш еще раз
- Перезапустите сервер

### "Table 'actions' doesn't exist"
```bash
cd b5-db-2
php artisan migrate
```

### "Class 'App\Models\Action' not found"
```bash
cd b5-api-2
composer dump-autoload
```

### Пустой список компаний
Добавьте тестовую компанию через GraphQL Playground или проверьте, что есть активные компании в БД.
