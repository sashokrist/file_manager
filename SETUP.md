# Настройка на Archive API

## Стъпки за инсталация

### Локална среда (тест)

API папката е в главната директория на проекта (напр. `biomarketERPWin/api/`).

### Production среда (сървър)

API папката трябва да бъде качена в `public_html` на сървъра (напр. `public_html/api/`).

### 1. Създаване на базата данни

Изпълнете SQL файла в MySQL:
```bash
mysql -u root -p < database.sql
```

Или ръчно в MySQL:
```sql
CREATE DATABASE archive_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE archive_api;
-- Изпълнете останалите команди от database.sql
```

### 2. Конфигурация

Редактирайте `api/config.php` и задайте правилните данни за базата данни:
- `DB_HOST` - хост на базата данни (обикновено '127.0.0.1' за локално, или IP/домейн за сървър)
- `DB_PORT` - порт (обикновено '3306')
- `DB_NAME` - име на базата данни ('archive_api')
- `DB_USER` - потребителско име за базата данни
- `DB_PASS` - парола за базата данни

### 3. Права за директории

**Локално (Windows):**
- Уверете се, че папката `api/uploads/` съществува и има права за запис.

**Production (Linux):**
Уверете се, че папката `api/uploads/` има права за запис:
```bash
chmod 755 api/uploads/
```

### 4. PHP настройки

Уверете се, че в `php.ini` са зададени:
```
upload_max_filesize = 256M
post_max_size = 256M
max_execution_time = 300
max_input_time = 300
```

## Тестване на API

Можете да тествате API-то директно:

### Качване на файл:
```bash
curl -X POST "http://localhost/biomarketERPWin/api/index.php?action=upload" \
  -F "user_id=1" \
  -F "username=testuser" \
  -F "file=@/path/to/file.pdf"
```

### Списък на файлове:
```bash
curl "http://localhost/biomarketERPWin/api/index.php?action=list&user_id=1&username=testuser"
```

## Структура на файлове

- Всеки потребител има главна директория с малкото си име (username в lowercase)
- Файловете се запазват в: `api/uploads/{username}/`
- Поддиректориите се създават според нуждите

## Безопасност

- API-то не проверява автентикация - това се прави от Laravel приложението
- Всички пътища се санитизират
- Файловете се запазват с уникални имена
