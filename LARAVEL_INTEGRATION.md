# Laravel Integration Guide

## Настройка

### 1. Добави в `.env`:
```env
ARCHIVE_API_URL=https://your-domain.com/api
ARCHIVE_API_MAX_RETRIES=3
ARCHIVE_API_RETRY_DELAY=1
ARCHIVE_API_TIMEOUT=300
ARCHIVE_API_CONNECT_TIMEOUT=30
```

### 2. Създай config файл `config/archive_api.php`:
```php
<?php

return [
    'url' => env('ARCHIVE_API_URL', 'http://localhost/api'),
    'max_retries' => env('ARCHIVE_API_MAX_RETRIES', 3),
    'retry_delay' => env('ARCHIVE_API_RETRY_DELAY', 1),
    'timeout' => env('ARCHIVE_API_TIMEOUT', 300),
    'connect_timeout' => env('ARCHIVE_API_CONNECT_TIMEOUT', 30),
];
```

### 3. Копирай `LaravelArchiveApiHelper.php` в `app/Services/ArchiveApiService.php`

### 4. Обнови namespace в файла:
```php
namespace App\Services;
```

## Използване

### Upload файл:
```php
use App\Services\ArchiveApiService;

$api = new ArchiveApiService();
$result = $api->uploadFile(
    $request->file('file'),
    auth()->id(),
    auth()->user()->username,
    'directory/path'
);
```

### Copy файл:
```php
$result = $api->copyFile(
    $fileId,
    auth()->id(),
    'target/directory/path'
);
```

### List файлове:
```php
$result = $api->listFiles(
    auth()->id(),
    auth()->user()->username,
    'directory/path'
);
```

## Особености

- **Автоматичен retry** при connection drops
- **Exponential backoff** между опитите
- **Keep-alive connections** за по-добра производителност
- **Streaming** за големи файлове
- **Connection timeout** защита
- **Логиране** на всички опити и грешки

## Подобрения в API-то

API-то вече има:
- ✅ Retry логика за file uploads
- ✅ Connection check преди всяка операция
- ✅ Database reconnection при connection loss
- ✅ Keep-alive headers
- ✅ Оптимизирани timeout настройки
- ✅ Подобрена обработка на големи файлове (2GB)
