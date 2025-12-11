# Генерация номера договора

## Описание

При создании договора автоматически генерируется уникальный номер по шаблону `DOC-XXXX-1234`, где:
- `DOC` - префикс для договоров
- `XXXX` - 4 случайные заглавные буквы (A-Z)
- `1234` - 4 случайные цифры (0000-9999)

## Примеры номеров

- `DOC-ABCD-1234`
- `DOC-XYZW-5678`
- `DOC-QWER-0001`

## Реализация

### Автоматическая генерация

Номер генерируется автоматически в модели `Contract` при создании записи, если поле `contract_number` не заполнено:

```php
// В модели Contract
static::creating(function ($contract) {
    if (empty($contract->contract_number)) {
        do {
            $letters = '';
            for ($i = 0; $i < 4; $i++) {
                $letters .= chr(rand(65, 90)); // A-Z
            }
            $digits = str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $contractNumber = 'DOC-' . $letters . '-' . $digits;
        } while (Contract::where('contract_number', $contractNumber)->exists());
        
        $contract->contract_number = $contractNumber;
    }
});
```

### Уникальность

Система проверяет уникальность номера в базе данных и генерирует новый, если такой номер уже существует.

## Использование

### GraphQL мутация

При создании договора через GraphQL поле `contract_number` необязательно:

```graphql
mutation CreateContract($input: CreateContractInput!) {
    createContract(input: $input) {
        id
        contract_number  # Будет сгенерирован автоматически
        project_id
        company_id
        contract_date
    }
}
```

### Ручное указание номера

Если требуется указать номер вручную, можно передать его в `contract_number`:

```graphql
mutation {
    createContract(input: {
        project_id: "01234567-89ab-cdef-0123-456789abcdef"
        company_id: "01234567-89ab-cdef-0123-456789abcdef"
        contract_number: "DOC-CUSTOM-0001"  # Ручной номер
        contract_date: "2024-01-15"
        planned_completion_date: "2024-12-31"
    }) {
        id
        contract_number
    }
}
```

## Связь с проектами

Аналогичная логика используется для генерации номеров проектов с префиксом `PRO-`:
- Проекты: `PRO-XXXX-1234`
- Договоры: `DOC-XXXX-1234`

## Файлы

- Модель: `app/Models/Contract.php`
- Мутация: `app/GraphQL/Mutations/CreateContract.php`
- Миграция: `b5-db-2/database/migrations/*_create_contracts_table.php`
