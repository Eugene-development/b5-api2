# Исправление загрузки изображений в КП техзаданий

## Дата: 6 декабря 2025

## Проблема

При попытке загрузить коммерческое предложение (КП) в виде изображения (JPG, PNG) возникала ошибка валидации. Система отклоняла файлы с сообщением о недопустимом типе файла.

## Причина

В сервисе `TzFileUploadService` для коммерческих предложений были разрешены только документы:
- PDF
- DOC/DOCX
- XLS/XLSX

Изображения были разрешены только для эскизов (`sketch`), но не для коммерческих предложений (`commercial_offer`).

## Решение

**Файл:** `b5-api-2/app/Services/TzFileUploadService.php`

Добавлены типы изображений в список разрешенных для коммерческих предложений:

```php
/**
 * Allowed MIME types for commercial offer files
 */
private const ALLOWED_OFFER_TYPES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    // Allow images for commercial offers
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/svg+xml',
];
```

## Результат

✅ Коммерческие предложения можно загружать как документы (PDF, DOC, XLS)  
✅ Коммерческие предложения можно загружать как изображения (JPG, PNG, GIF, WEBP, SVG)  
✅ Эскизы продолжают поддерживать только изображения  
✅ Максимальный размер файла остается 10MB  

## Поддерживаемые форматы

### Эскизы (Sketch)
- JPEG/JPG
- PNG
- GIF
- WEBP
- SVG

### Коммерческие предложения (Commercial Offer)
- PDF
- DOC/DOCX
- XLS/XLSX
- JPEG/JPG
- PNG
- GIF
- WEBP
- SVG

## Тестирование

1. Откройте страницу `/tz`
2. Нажмите кнопку "Загрузить КП" на любом техзадании
3. Выберите изображение (JPG или PNG)
4. Нажмите "Загрузить"
5. Убедитесь, что файл успешно загружен
6. Проверьте, что счетчик КП увеличился

## Связанные файлы

- `b5-api-2/app/Services/TzFileUploadService.php` - сервис загрузки файлов
- `b5-api-2/app/GraphQL/Mutations/UploadTzFile.php` - GraphQL мутация
- `b5-admin/src/lib/components/modals/FileUploadModal.svelte` - модальное окно загрузки

## Примечания

- Валидация типов файлов происходит на бэкенде по MIME-типу
- Фронтенд также ограничивает выбор файлов через атрибут `accept`
- Логирование помогает отслеживать попытки загрузки и ошибки валидации
