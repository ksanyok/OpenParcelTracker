<?php
/**
 * Central DB connection + schema bootstrap (SQLite or MySQL).
 *
 * Set environment variables in .env file:
 *   DB_DRIVER=mysql
 *   DB_HOST=localhost
 *   DB_NAME=tracker
 *   DB_USER=username
 *   DB_PASS=secret
 *   DB_CHARSET=utf8mb4
 *
 * For SQLite, set DB_DRIVER=sqlite
 *
 * Include:
 *   - public: require_once __DIR__ . '/db.php';
 *   - admin : require_once __DIR__ . '/../db.php';
 */

// Load .env file if exists
if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

const DB_DIR  = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/tracker.sqlite';
const VERSION = '1.9.1';

/**
 * Get the current version of the application.
 */
function get_version(): string {
    return VERSION;
}

/**
 * Get the last update timestamp of the database.
 */
function get_last_update(): string {
    $date = exec('git log -1 --format=%cd --date=short 2>/dev/null');
    return $date ? trim($date) : date('Y-m-d');
}

/**
 * Get shared PDO instance and ensure schema exists.
 */
function pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = getenv('DB_DRIVER') ?: 'mysql';

    if ($driver === 'mysql') {
        $host    = getenv('DB_HOST');
        $db      = getenv('DB_NAME');
        $user    = getenv('DB_USER');
        $pass    = getenv('DB_PASS');
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

        if (!$host || !$db || !$user) {
            die('Database configuration incomplete. Please set DB_HOST, DB_NAME, DB_USER in .env file.');
        }

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        ensure_schema($pdo, 'mysql');
    } else {
        if (!is_dir(DB_DIR)) {
            @mkdir(DB_DIR, 0775, true);
        }
        $dsn = 'sqlite:' . DB_FILE;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        ensure_schema($pdo, 'sqlite');
    }

    return $pdo;
}

/**
 * Create required tables if they don't exist and bootstrap default admin.
 * Uses different DDL for sqlite vs mysql.
 */
function ensure_schema(PDO $pdo, string $driver='sqlite'): void {
    if ($driver === 'mysql') {
        // Users table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Packages table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS packages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tracking_number VARCHAR(191) NOT NULL UNIQUE,
                title VARCHAR(255) NULL,
                last_lat DOUBLE NULL,
                last_lng DOUBLE NULL,
                last_address VARCHAR(500) NULL,
                status VARCHAR(50) NULL,
                image_path VARCHAR(500) NULL,
                arriving VARCHAR(255) NULL,
                destination VARCHAR(255) NULL,
                delivery_option VARCHAR(255) NULL,
                description TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Locations table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS locations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                package_id INT NOT NULL,
                lat DOUBLE NOT NULL,
                lng DOUBLE NOT NULL,
                address VARCHAR(500) NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (package_id),
                CONSTRAINT fk_locations_packages FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        // sqlite
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tracking_number TEXT UNIQUE NOT NULL,
                title TEXT,
                last_lat REAL,
                last_lng REAL,
                last_address TEXT,
                status TEXT,
                image_path TEXT,
                arriving TEXT,
                destination TEXT,
                delivery_option TEXT,
                description TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS locations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                package_id INTEGER NOT NULL,
                lat REAL NOT NULL,
                lng REAL NOT NULL,
                address TEXT,
                note TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
            )
        ");
    }

    // bootstrap default admin if empty
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($cnt === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $now  = date('Y-m-d H:i:s');
        $stm  = $pdo->prepare("INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?,?,1,?)");
        $stm->execute(['admin', $hash, $now]);
    }
}
