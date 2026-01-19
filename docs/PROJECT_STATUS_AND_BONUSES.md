# Логика бонусов при смене статуса проекта

## Обзор

Данный документ описывает логику работы бонусов в зависимости от статуса проекта и роли куратора.

## Статусы проекта

| Slug | Название | Описание |
|------|----------|----------|
| `new-project` | Новый проект | Проект создан агентом, куратор не назначен |
| `curator-processing` | Принят куратором | Проект принят куратором, куратор назначен |
| `bonus-paid` | Проект закрыт | Бонусы выплачены |
| `client-refused` | Отказ | Клиент отказался от проекта |

## Логика бонусов

### 1. Создание бонуса агенту

**Бонус агенту создаётся сразу** при создании договора или заказа, **независимо от статуса проекта**.

- При создании договора/заказа вызывается `BonusService::createBonusForContract()` / `createBonusForOrder()`
- Бонус агента создаётся с `recipient_type = 'agent'`
- Агент видит бонусы всегда, независимо от наличия куратора и статуса проекта

### 2. Бонус куратору при смене статуса

#### При переходе в статус "Принят куратором" (`curator-processing`):

1. **Назначается куратор** — текущий пользователь, сменивший статус
2. **Создаются бонусы куратору** для всех существующих активных договоров и заказов проекта
3. Бонусы создаются с `recipient_type = 'curator'` и `user_id` = ID куратора

#### При переходе в статус "Новый проект" (`new-project`) из "Принят куратором":

1. **Удаляются все бонусы куратора** для данного проекта (только невыплаченные)
2. **Удаляется связь куратора** с проектом
3. Бонусы агента остаются нетронутыми

### 3. Смена куратора

Если другой куратор меняет статус обратно на "Принят куратором":

1. Удаляются бонусы предыдущего куратора
2. Создаются новые бонусы для нового куратора
3. Агентские бонусы не затрагиваются

## Методы BonusService

### Новые методы

```php
// Удалить все кураторские бонусы проекта
public function removeCuratorBonusesForProject(string $projectId): int

// Создать бонусы куратора для всех договоров и заказов проекта
public function createCuratorBonusesForProject(string $projectId, int $curatorId): int

// Создать бонус куратора для договора с указанным куратором
public function createCuratorBonusForContractWithCurator(Contract $contract, int $curatorId): ?Bonus

// Создать бонус куратора для заказа с указанным куратором
public function createCuratorBonusForOrderWithCurator(Order $order, int $curatorId): ?Bonus
```

### Существующие методы

```php
// Создать бонус агента для договора
public function createBonusForContract(Contract $contract): ?Bonus

// Создать бонус агента для заказа
public function createBonusForOrder(Order $order): ?Bonus

// Создать бонус куратора для договора (использует getCuratorIdFromProject)
public function createCuratorBonusForContract(Contract $contract): ?Bonus

// Создать бонус куратора для заказа (использует getCuratorIdFromProject)
public function createCuratorBonusForOrder(Order $order): ?Bonus
```

## GraphQL мутации

### UpdateProject

При вызове мутации `UpdateProject` с изменением `status_id`:

```graphql
mutation UpdateProject($id: ID!, $status_id: ID!) {
  updateProject(id: $id, status_id: $status_id) {
    id
    status {
      slug
      value
    }
    curator {
      id
      name
    }
  }
}
```

- Если новый статус = `curator-processing`:
  - Назначается куратор (текущий пользователь)
  - Создаются бонусы куратору
- Если новый статус = `new-project` и старый = `curator-processing`:
  - Удаляется куратор
  - Удаляются бонусы куратора

### AcceptProject

Мутация `AcceptProject` используется для назначения куратора на проект:

```graphql
mutation AcceptProject($projectId: ID!, $userId: Int!, $statusId: ID) {
  acceptProject(projectId: $projectId, userId: $userId, statusId: $statusId) {
    id
    user_id
    project_id
    role
  }
}
```

При назначении куратора автоматически создаются бонусы для всех существующих договоров и заказов.

## Важные особенности

1. **Агент видит свои бонусы всегда** — независимо от статуса проекта и наличия куратора
2. **Бонусы куратора временные** — они существуют только пока проект в статусе "Принят куратором"
3. **Выплаченные бонусы не удаляются** — удаляются только бонусы с `paid_at = null`
4. **При смене куратора** бонусы переназначаются новому куратору
5. **Реферальные бонусы** не затрагиваются логикой смены статуса проекта

## Диаграмма состояний

```
┌─────────────────┐
│  Новый проект   │
│  (new-project)  │
└────────┬────────┘
         │ Куратор меняет статус
         ▼
┌─────────────────────────┐
│   Принят куратором      │
│   (curator-processing)  │◄────┐
└────────┬────────────────┘     │ Другой куратор
         │                      │ принимает
         │ Куратор меняет       │
         │ на "Новый проект"    │
         ▼                      │
┌─────────────────┐            │
│  Новый проект   │────────────┘
│  (new-project)  │
└─────────────────┘
```

## Логирование

Все операции с бонусами записываются в лог:

```
BonusService: Removed curator bonuses for project
BonusService: Created curator bonuses for project
BonusService: Created curator bonus for contract
BonusService: Created curator bonus for order
UpdateProject: Removed curator and bonuses from project
UpdateProject: Created curator bonuses for project
```
