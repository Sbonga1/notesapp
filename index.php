<?php

// Get an environment variable or use a default value.
function envv($key, $default = '') {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

// Escape output before showing it in HTML.
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Read a value from the Azure SQL connection string.
function connection_value($connectionString, $key, $default = '') {
    $parts = explode(';', $connectionString);

    foreach ($parts as $part) {
        if (strpos($part, '=') === false) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $part, 2));

        if (strtolower($name) === strtolower($key)) {
            return $value;
        }
    }

    return $default;
}

// Convert SQL Server errors into readable text.
function sql_errors_text() {
    $errors = sqlsrv_errors();

    if ($errors === null) {
        return 'No SQL Server error details were returned.';
    }

    $messages = [];

    foreach ($errors as $error) {
        $messages[] = 'SQLSTATE: ' . $error['SQLSTATE'] .
            ' | Code: ' . $error['code'] .
            ' | Message: ' . $error['message'];
    }

    return implode("\n", $messages);
}

// Make sure the SQL Server PHP driver is available.
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo "<h1>SQL Server PHP driver missing</h1>";
    echo "<p>The app needs the sqlsrv PHP extension to connect to Azure SQL Database.</p>";
    exit;
}

// Azure App Service stores SQLAzure connection strings using this name format.
$connectionString = envv('SQLAZURECONNSTR_DefaultConnection');

// Read server and database from the Azure connection string.
$sqlServer = connection_value($connectionString, 'Server');
$sqlDatabase = connection_value($connectionString, 'Database');

// Some Azure connection strings use Initial Catalog instead of Database.
if ($sqlDatabase === '') {
    $sqlDatabase = connection_value($connectionString, 'Initial Catalog');
}

// Fallback values if you decide to use normal App Settings instead.
$sqlServer = envv('SQLSERVER_HOST', $sqlServer);
$sqlDatabase = envv('SQLSERVER_DB', $sqlDatabase);

// Remove tcp: if Azure added it to the server name.
$sqlServer = str_replace('tcp:', '', $sqlServer);

try {
    // 1) Connect to Azure SQL Database using Managed Identity.
    // Do not use username or password with Microsoft Entra-only authentication.
    $connectionOptions = [
        'Database' => $sqlDatabase,
        'Authentication' => 'ActiveDirectoryMsi',
        'Encrypt' => true,
        'TrustServerCertificate' => false,
        'CharacterSet' => 'UTF-8'
    ];

    $conn = sqlsrv_connect($sqlServer, $connectionOptions);

    if ($conn === false) {
        throw new Exception(sql_errors_text());
    }

    // 2) Create the table if it does not exist.
    $createTableSql = "
        IF OBJECT_ID('dbo.notes', 'U') IS NULL
        BEGIN
            CREATE TABLE dbo.notes (
                id INT IDENTITY(1,1) PRIMARY KEY,
                title NVARCHAR(120) NOT NULL,
                body NVARCHAR(MAX) NOT NULL,
                created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME()
            );
        END
    ";

    $stmt = sqlsrv_query($conn, $createTableSql);

    if ($stmt === false) {
        throw new Exception(sql_errors_text());
    }

    $notice = '';

    // 3) Handle form actions.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            // Add a note.
            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');

            if ($title !== '' && $body !== '') {
                $stmt = sqlsrv_query(
                    $conn,
                    "INSERT INTO dbo.notes (title, body) VALUES (?, ?)",
                    [$title, $body]
                );

                if ($stmt === false) {
                    throw new Exception(sql_errors_text());
                }

                $notice = 'Note added successfully.';
            }
        }

        if ($action === 'update') {
            // Update a note.
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');

            if ($id > 0 && $title !== '' && $body !== '') {
                $stmt = sqlsrv_query(
                    $conn,
                    "UPDATE dbo.notes SET title = ?, body = ? WHERE id = ?",
                    [$title, $body, $id]
                );

                if ($stmt === false) {
                    throw new Exception(sql_errors_text());
                }

                $notice = 'Note updated successfully.';
            }
        }

        if ($action === 'delete') {
            // Delete a note.
            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                $stmt = sqlsrv_query(
                    $conn,
                    "DELETE FROM dbo.notes WHERE id = ?",
                    [$id]
                );

                if ($stmt === false) {
                    throw new Exception(sql_errors_text());
                }

                $notice = 'Note deleted successfully.';
            }
        }
    }

    // 4) Load notes.
    $stmt = sqlsrv_query(
        $conn,
        "SELECT id, title, body, CONVERT(VARCHAR(19), created_at, 120) AS created_at
         FROM dbo.notes
         ORDER BY id DESC"
    );

    if ($stmt === false) {
        throw new Exception(sql_errors_text());
    }

    $notes = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $notes[] = $row;
    }

    // 5) Check if the user is editing a note.
    $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

} catch (Throwable $e) {
    // Show a simple error during deployment testing.
    http_response_code(500);
    echo "<h1>Database connection failed</h1>";
    echo "<p>Check Managed Identity, SQLAzure connection string, database user permissions, and firewall settings.</p>";
    echo "<pre>" . h($e->getMessage()) . "</pre>";
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Notes App</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            margin: 0;
            padding: 30px;
            color: #172033;
        }
        .container {
            max-width: 850px;
            margin: auto;
        }
        .card {
            background: white;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 18px;
            box-shadow: 0 8px 24px rgba(0,0,0,.08);
        }
        h1, h2 {
            margin-top: 0;
        }
        label {
            display: block;
            margin-top: 12px;
            font-weight: bold;
        }
        input, textarea {
            width: 100%;
            padding: 11px;
            margin-top: 6px;
            border: 1px solid #cdd7e5;
            border-radius: 8px;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
        }
        button, .button {
            display: inline-block;
            background: #005baa;
            color: white;
            border: 0;
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 12px;
            text-decoration: none;
        }
        .danger {
            background: #b42318;
        }
        .muted {
            color: #667085;
            font-size: 14px;
        }
        .notice {
            background: #e7f8ee;
            color: #05603a;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .note {
            border-top: 1px solid #e5e7eb;
            padding-top: 15px;
            margin-top: 15px;
        }
        .actions form {
            display: inline;
        }
        .top {
            background: #005baa;
            color: white;
        }
        .top p {
            color: #e6f0ff;
        }
    </style>
</head>
<body>
<div class="container">

    <div class="card top">
        <h1>Mini PHP + MySQL App</h1>
        <p>Notes app with crud operations</p>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Add a Note</h2>
        <form method="post">
            <input type="hidden" name="action" value="create">

            <label>Title</label>
            <input name="title" required placeholder="Example: First Azure test">

            <label>Body</label>
            <textarea name="body" required placeholder="Write a simple note..."></textarea>

            <button type="submit">Save Note</button>
        </form>
    </div>

    <div class="card">
        <h2>Saved Notes</h2>
        <p class="muted">If notes appear here, your PHP app is successfully talking to MySQL.</p>

        <?php if (count($notes) === 0): ?>
            <p>No notes yet. Add one above.</p>
        <?php endif; ?>

        <?php foreach ($notes as $note): ?>
            <div class="note">
                <?php if ($editId === (int)$note['id']): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= h($note['id']) ?>">

                        <label>Title</label>
                        <input name="title" value="<?= h($note['title']) ?>" required>

                        <label>Body</label>
                        <textarea name="body" required><?= h($note['body']) ?></textarea>

                        <button type="submit">Update</button>
                        <a class="button" href="index.php">Cancel</a>
                    </form>
                <?php else: ?>
                    <h3><?= h($note['title']) ?></h3>
                    <p><?= nl2br(h($note['body'])) ?></p>
                    <p class="muted">Created: <?= h($note['created_at']) ?></p>

                    <div class="actions">
                        <a class="button" href="?edit=<?= h($note['id']) ?>">Edit</a>

                        <form method="post" onsubmit="return confirm('Delete this note?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= h($note['id']) ?>">
                            <button class="danger" type="submit">Delete</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

</div>
</body>
</html>
