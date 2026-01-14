# Archive API

Просто API за управление на файлове и директории.

## Инсталация

### Локална среда (тест)

1. API папката е в главната директория на проекта (напр. `C:\wamp64\www\biomarketERPWin\api\`)

2. Създайте базата данни:
```sql
mysql -u root -p < database.sql
```

Или изпълнете SQL файла ръчно в MySQL.

3. Настройте `config.php` с правилните данни за базата данни.

4. Уверете се, че папката `api/uploads/` е създадена и има права за запис.

### Production среда (сървър)

1. Качете API папката в `public_html` на сървъра (напр. `public_html/api/`)

2. Създайте базата данни на сървъра и настройте `config.php` с production данните.

3. Уверете се, че папката `api/uploads/` има права за запис (chmod 755).

## API Endpoints

### Upload File
```
POST /api/index.php?action=upload
Parameters:
- user_id (required)
- username (required)
- directory_path (optional)
- file (required, multipart/form-data)
```

### Create Directory
```
POST /api/index.php?action=create_directory
Parameters:
- user_id (required)
- username (required)
- name (required)
- parent_path (optional)
```

### List Files and Directories
```
GET /api/index.php?action=list&user_id=X&username=Y&path=...
Parameters:
- user_id (required)
- username (required)
- path (optional)
```

### Download File
```
GET /api/index.php?action=download&id=X
Parameters:
- id (required)
```

### Delete File
```
DELETE /api/index.php?action=delete_file&id=X
Parameters:
- id (required)
```

### Delete Directory
```
DELETE /api/index.php?action=delete_directory&id=X
Parameters:
- id (required)
```

## Структура

- Всеки потребител има главна директория с малкото си име (username в lowercase)
- Файловете се запазват в главната директория или в поддиректории
- Максимален размер на файл: 256MB
