<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * OpenParcelTracker Auto-Installer
 * This script sets up the database.
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

    // For MySQL, create database if not exists
    if ($dbDriver === 'mysql') {
        try {
            $pdo_temp = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Exception $e) {
            die('Failed to create database: ' . $e->getMessage());
        }
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

    // Download and extract the repository
    $repoUrl = 'https://github.com/ksanyok/OpenParcelTracker/archive/refs/heads/main.zip';
    $zipFile = __DIR__ . '/temp.zip';
    $extractDir = __DIR__ . '/temp';

    // Download the zip
    $zipContent = file_get_contents($repoUrl);
    if ($zipContent === false) {
        die('Failed to download repository zip.');
    }
    file_put_contents($zipFile, $zipContent);

    // Extract the zip
    $zip = new ZipArchive();
    if ($zip->open($zipFile) === TRUE) {
        $zip->extractTo($extractDir);
        $zip->close();
    } else {
        unlink($zipFile);
        die('Failed to extract repository zip.');
    }
    unlink($zipFile);

    // Find the extracted folder (should be temp/OpenParcelTracker-main)
    $extractedFolders = scandir($extractDir);
    $repoFolder = null;
    foreach ($extractedFolders as $folder) {
        if ($folder !== '.' && $folder !== '..' && is_dir($extractDir . '/' . $folder)) {
            $repoFolder = $extractDir . '/' . $folder;
            break;
        }
    }
    if (!$repoFolder) {
        die('Failed to find extracted repository folder.');
    }

    // Function to move files recursively
    function move_recursive($src, $dst) {
        if (is_dir($src)) {
            if (!is_dir($dst)) mkdir($dst, 0755, true);
            $files = scandir($src);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    move_recursive($src . '/' . $file, $dst . '/' . $file);
                }
            }
            rmdir($src);
        } else {
            rename($src, $dst);
        }
    }

    // Move files to current directory
    $files = scandir($repoFolder);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $src = $repoFolder . '/' . $file;
            $dst = __DIR__ . '/' . $file;
            move_recursive($src, $dst);
        }
    }

    // Clean up temp dir
    rmdir($repoFolder);
    rmdir($extractDir);

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