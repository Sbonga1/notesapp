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

// Get a Microsoft Entra token using Azure App Service Managed Identity.
function get_mysql_token() {
    $endpoint = envv('IDENTITY_ENDPOINT');
    $header = envv('IDENTITY_HEADER');

    // Local fallback for testing only.
    if ($endpoint === '' || $header === '') {
        return envv('DB_TOKEN');
    }

    // Azure MySQL Entra authentication resource.
    $resource = urlencode('https://ossrdbms-aad.database.windows.net');
    $clientId = envv('AZURE_CLIENT_ID');

    $url = $endpoint . '?api-version=2019-08-01&resource=' . $resource;

    // Use user-assigned managed identity if provided.
    if ($clientId !== '') {
        $url .= '&client_id=' . urlencode($clientId);
    }

    // Request the token from Azure.
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-IDENTITY-HEADER: ' . $header
        ]
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception('Could not get Managed Identity token.');
    }

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
        throw new Exception('Managed Identity token was not returned.');
    }

    return $data['access_token'];
}

// Database settings from Azure App Service.
$dbHost = envv('DB_HOST');
$dbPort = (int) envv('DB_PORT', '3306');
$dbName = envv('DB_NAME', 'notes_php_app');
$dbUser = envv('DB_USER');
$dbPass = get_mysql_token();

// Make MySQL errors easier to catch.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // 1) Allow the access token to be sent to MySQL.
    putenv('LIBMYSQL_ENABLE_CLEARTEXT_PLUGIN=1');

    // 2) Connect to Azure MySQL using Managed Identity.
    $conn = mysqli_init();

    // Required for Azure MySQL SSL connection.
    mysqli_ssl_set($conn, null, null, null, null, null);

    mysqli_real_connect(
        $conn,
        $dbHost,
        $dbUser,
        $dbPass,
        $dbName,
        $dbPort,
        null,
        MYSQLI_CLIENT_SSL
    );

    // 3) Set the character encoding.
    $conn->set_charset('utf8mb4');

    // 4) Create the table if it does not exist.
    $conn->query("
        CREATE TABLE IF NOT EXISTS notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $notice = '';

    // 5) Handle form actions.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            // Add a note.
            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');

            if ($title !== '' && $body !== '') {
                $stmt = $conn->prepare("INSERT INTO notes (title, body) VALUES (?, ?)");
                $stmt->bind_param("ss", $title, $body);
                $stmt->execute();
                $notice = 'Note added successfully.';
            }
        }

        if ($action === 'update') {
            // Update a note.
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');

            if ($id > 0 && $title !== '' && $body !== '') {
                $stmt = $conn->prepare("UPDATE notes SET title = ?, body = ? WHERE id = ?");
                $stmt->bind_param("ssi", $title, $body, $id);
                $stmt->execute();
                $notice = 'Note updated successfully.';
            }
        }

        if ($action === 'delete') {
            // Delete a note.
            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                $stmt = $conn->prepare("DELETE FROM notes WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $notice = 'Note deleted successfully.';
            }
        }
    }

    // 6) Load notes.
    $result = $conn->query("SELECT id, title, body, created_at FROM notes ORDER BY id DESC");
    $notes = $result->fetch_all(MYSQLI_ASSOC);

    // 7) Check if the user is editing a note.
    $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

} catch (Throwable $e) {
    // Show a simple error during deployment testing.
    http_response_code(500);
    echo "<h1>Database connection failed</h1>";
    echo "<p>Check Managed Identity, DB_USER, MySQL Entra user, and firewall/network settings.</p>";
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
