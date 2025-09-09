<?php
/**
 * OpenParcelTracker Auto-Installer
 * This script clones the latest stable version from the repository and sets up the database.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle installation
    $dbHost = $_POST['db_host'] ?? '';
    $dbName = $_POST['db_name'] ?? '';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_pass'] ?? '';
    $dbDriver = $_POST['db_driver'] ?? 'mysql';

    // Validate inputs
    if ($dbDriver === 'mysql' && (empty($dbHost) || empty($dbName) || empty($dbUser))) {
        die('All database fields are required for MySQL.');
    } elseif ($dbDriver === 'sqlite' && empty($dbName)) {
        die('Database name is required for SQLite.');
    }

    // Create .env file
    $envContent = "DB_DRIVER=$dbDriver\n";
    $envContent .= "DB_HOST=$dbHost\n";
    $envContent .= "DB_NAME=$dbName\n";
    $envContent .= "DB_USER=$dbUser\n";
    $envContent .= "DB_PASS=$dbPass\n";
    $envContent .= "DB_CHARSET=utf8mb4\n";
    file_put_contents(__DIR__ . '/.env', $envContent);

    // Set environment variables for current process
    putenv("DB_DRIVER=$dbDriver");
    putenv("DB_HOST=$dbHost");
    putenv("DB_NAME=$dbName");
    putenv("DB_USER=$dbUser");
    putenv("DB_PASS=$dbPass");
    putenv("DB_CHARSET=utf8mb4");

    // Clone the repository
    $repoUrl = 'https://github.com/ksanyok/OpenParcelTracker.git';
    $installDir = __DIR__ . '/OpenParcelTracker';

    if (is_dir($installDir)) {
        // If directory exists, pull latest
        chdir($installDir);
        exec('git pull origin main', $output, $returnVar);
        if ($returnVar !== 0) {
            die('Failed to update repository: ' . implode("\n", $output));
        }
    } else {
        // Clone
        exec("git clone $repoUrl $installDir", $output, $returnVar);
        if ($returnVar !== 0) {
            die('Failed to clone repository: ' . implode("\n", $output));
        }
    }

    // Move files to current directory if needed
    // For simplicity, assume installer is in root, and clone into subdir, then move
    if ($installDir !== __DIR__) {
        // Move contents
        $files = scandir($installDir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                rename("$installDir/$file", __DIR__ . "/$file");
            }
        }
        rmdir($installDir);
    }

    // Include db.php to set up database
    require_once __DIR__ . '/db.php';
    try {
        $pdo = pdo();
        echo 'Database setup completed successfully!';
    } catch (Exception $e) {
        die('Database setup failed: ' . $e->getMessage());
    }

    echo '<br><a href="index.php">Go to the application</a>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OpenParcelTracker Installer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        form { max-width: 400px; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 8px; margin-top: 5px; }
        button { margin-top: 20px; padding: 10px; background: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>OpenParcelTracker Auto-Installer</h1>
    <p>This installer will download the latest version from the repository and set up the database.</p>
    <form method="post">
        <label for="db_driver">Database Driver:</label>
        <select name="db_driver" id="db_driver">
            <option value="mysql">MySQL</option>
            <option value="sqlite">SQLite</option>
        </select>

        <label for="db_host">Database Host:</label>
        <input type="text" name="db_host" id="db_host" placeholder="e.g. localhost">

        <label for="db_name">Database Name:</label>
        <input type="text" name="db_name" id="db_name" placeholder="e.g. tracker" required>

        <label for="db_user">Database User:</label>
        <input type="text" name="db_user" id="db_user" placeholder="e.g. root">

        <label for="db_pass">Database Password:</label>
        <input type="password" name="db_pass" id="db_pass">

        <button type="submit">Install</button>
    </form>
</body>
</html>