# Mini PHP + MySQL App for Azure Deployment

This is intentionally simple.

It has only one real file:

- `index.php`

The app connects to MySQL, creates the database if missing, creates one table called `notes`, and lets you add, view, edit, and delete notes.

## Database used by the app

Database name:

```text
mini_php_app
```

Table name:

```text
notes
```

Table structure created automatically:

```sql
CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Local XAMPP setup

1. Start Apache and MySQL.
2. Place the folder in `htdocs`.
3. Open:

```text
http://localhost/mini_php_mysql_app
```

The app will create the database and table automatically.

## Azure App Service settings

In Azure App Service, add these environment variables:

```text
DB_HOST=your-mysql-server.mysql.database.azure.com
DB_PORT=3306
DB_NAME=mini_php_app
DB_USER=your_mysql_username
DB_PASS=your_mysql_password
DB_SSL=true
```

## Azure deployment

Zip the contents of this folder and deploy to Azure App Service.

Example:

```bash
az webapp deploy \
  --resource-group YOUR_RESOURCE_GROUP \
  --name YOUR_APP_SERVICE_NAME \
  --src-path mini_php_mysql_app.zip \
  --type zip
```
