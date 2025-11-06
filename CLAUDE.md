# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

B5 API 2 - GraphQL API сервер для системы Bonus5. Построен на **Laravel 12** с использованием **Lighthouse GraphQL**. Предоставляет единый GraphQL endpoint для фронтенд приложений (b5-agent, b5-admin).

Работает на порту **8000** по умолчанию.

## Commands

### Development
```bash
php artisan serve              # Запуск dev сервера (порт 8000)
npm run dev                    # Запуск Vite для фронтенд ассетов
```

### Database
```bash
php artisan migrate            # Выполнить миграции
php artisan migrate:fresh      # Пересоздать БД
php artisan migrate:rollback   # Откатить последнюю миграцию
php artisan db:seed            # Запустить seeders
```

### GraphQL
```bash
php artisan lighthouse:clear-cache       # Очистить кэш GraphQL схемы
./clear-graphql-cache.sh                 # Скрипт для очистки кэша
./UPDATE_SCHEMA.sh                       # Обновить схему после изменений
```

### Testing & Code Quality
```bash
php artisan test               # Запуск тестов (PHPUnit)
./vendor/bin/pint              # Форматирование кода (Laravel Pint)
php artisan pail               # Мониторинг логов в реальном времени
```

### Artisan Commands
```bash
php artisan tinker             # REPL для Laravel
php artisan route:list         # Список всех роутов
php artisan config:clear       # Очистить кэш конфигурации
php artisan cache:clear        # Очистить application cache
```

## Architecture

### GraphQL Schema Structure

GraphQL схема разбита на модульные файлы в `graphql/`:
- `schema.graphql` - главный файл схемы, импортирует все остальные
- `user.graphql` - пользователи, аутентификация
- `project.graphql` - проекты
- `company.graphql` - компании (агенты, кураторы, подрядчики, поставщики)
- `action.graphql` - акции
- `order.graphql` - заказы
- `project_offer.graphql` - предложения по проектам
- `project_sketch.graphql` - эскизы проектов
- `technical_specification.graphql` - технические задания

**Важно**: После изменения GraphQL схемы запустите `php artisan lighthouse:clear-cache` или `./UPDATE_SCHEMA.sh`.

### Application Structure

```
app/
├── GraphQL/            # GraphQL resolvers, queries, mutations, directives
├── Http/               # HTTP controllers (REST API endpoints)
├── Models/             # Eloquent models
├── Providers/          # Service providers
└── Services/           # Business logic services
```

### GraphQL Resolvers

- `app/GraphQL/Queries/` - GraphQL query resolvers
- `app/GraphQL/Mutations/` - GraphQL mutation resolvers
- Lighthouse автоматически связывает schema с resolvers по naming convention

### Models

Eloquent модели в `app/Models/`:
- `User` - пользователи системы
- `Project` - проекты
- `Company` - компании (полиморфные: Agent, Curator, Contractor, Supplier)
- `Order` - заказы
- `Action` - акции
- И другие...

### API Endpoints

**GraphQL Endpoint**: `/graphql` (POST)
**GraphiQL IDE**: `/graphiql` (GET, только для development)

REST API endpoints в `routes/api.php`:
- `POST /api/projects/public-submit` - публичная отправка проектов (по secret key, rate limit: 10/min)

### Authentication

Аутентификация обрабатывается через отдельный сервис **b5-auth-2**. API проверяет токены через middleware.

### Database

- Миграции: `database/migrations/`
- Seeders: `database/seeders/`
- Factories: `database/factories/`

### Configuration

Environment переменные в `.env`:
```
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=b5_db
DB_USERNAME=root
DB_PASSWORD=

# CORS configuration
FRONTEND_URL=http://localhost:5173    # b5-agent URL
ADMIN_URL=http://localhost:5174       # b5-admin URL
```

**Важно**: Настройки CORS для production описаны в `FIX_CORS_PRODUCTION.md`.

### Storage & File Uploads

Laravel использует filesystem для хранения файлов:
- Local: `storage/app/public/`
- S3: настраивается через `FILESYSTEM_DISK=s3` в `.env`
- Intervention Image для обработки изображений

## Key Technologies

- **Laravel 12** - PHP фреймворк
- **Lighthouse GraphQL 6** - GraphQL сервер для Laravel
- **MySQL** - база данных
- **Intervention Image** - обработка изображений
- **AWS S3** (optional) - облачное хранилище файлов
- **Laravel Vite Plugin** - для фронтенд ассетов

## Docker

Production deployment:
- `Dockerfile` - Docker image configuration
- `entrypoint.sh` - Docker entrypoint script
- См. `PRODUCTION_DEPLOY_STEPS.md` для инструкций

## Useful Scripts

- `./check-production-status.sh` - проверка статуса production сервера
- `./diagnose-cors.sh` - диагностика CORS проблем
- `./fix-cors-production.sh` - автоматическое исправление CORS в production
- `./test-accept-project.sh` - тестирование приёма проекта

## Documentation

- `RESTART_API.md` - инструкции по перезапуску API
- `UPDATE_ACTIONS_TABLE.md` - обновление таблицы actions
- `FIX_CORS_PRODUCTION.md` - решение CORS проблем
- `PRODUCTION_DEPLOY_STEPS.md` - шаги деплоя в production

## Development Workflow

1. Изменяйте GraphQL схему в `graphql/*.graphql`
2. Создавайте/обновляйте resolvers в `app/GraphQL/`
3. Добавляйте/изменяйте модели в `app/Models/`
4. Создавайте миграции: `php artisan make:migration`
5. Очищайте кэш GraphQL: `php artisan lighthouse:clear-cache`
6. Тестируйте в GraphiQL: `http://localhost:8000/graphiql`
