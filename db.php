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
const VERSION = '2.0.3';

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
                source VARCHAR(50) NULL DEFAULT 'manual',
                is_manual TINYINT(1) NOT NULL DEFAULT 1,
                event_dt_utc DATETIME NULL,
                tzid VARCHAR(100) NULL,
                utc_offset VARCHAR(10) NULL,
                event_dt_local DATETIME NULL,
                title VARCHAR(255) NULL,
                message TEXT NULL,
                from_city VARCHAR(100) NULL,
                from_state VARCHAR(100) NULL,
                from_country_code VARCHAR(2) NULL,
                to_city VARCHAR(100) NULL,
                to_state VARCHAR(100) NULL,
                to_country_code VARCHAR(2) NULL,
                facility_name VARCHAR(255) NULL,
                postal_code VARCHAR(20) NULL,
                event_code VARCHAR(50) NULL,
                status_code VARCHAR(50) NULL,
                carrier VARCHAR(100) NULL,
                piece_id VARCHAR(100) NULL,
                sequence INT NULL DEFAULT 0,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (package_id),
                INDEX (to_country_code),
                INDEX (event_dt_local),
                INDEX (event_dt_utc),
                INDEX (source),
                CONSTRAINT fk_locations_packages FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Settings key-value table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                s_key VARCHAR(191) NOT NULL UNIQUE,
                s_value TEXT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
                source TEXT DEFAULT 'manual',
                is_manual INTEGER NOT NULL DEFAULT 1,
                event_dt_utc TEXT,
                tzid TEXT,
                utc_offset TEXT,
                event_dt_local TEXT,
                title TEXT,
                message TEXT,
                from_city TEXT,
                from_state TEXT,
                from_country_code TEXT,
                to_city TEXT,
                to_state TEXT,
                to_country_code TEXT,
                facility_name TEXT,
                postal_code TEXT,
                event_code TEXT,
                status_code TEXT,
                carrier TEXT,
                piece_id TEXT,
                sequence INTEGER DEFAULT 0,
                created_by TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
            )
        ");

        // Settings key-value table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                s_key TEXT UNIQUE NOT NULL,
                s_value TEXT,
                updated_at TEXT NOT NULL
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

/**
 * Simple key/value settings helpers.
 */
function setting_get(string $key, ?string $default = null): ?string {
    $pdo = pdo();
    $stm = $pdo->prepare("SELECT s_value FROM settings WHERE s_key = ?");
    $stm->execute([$key]);
    $row = $stm->fetch();
    if ($row && array_key_exists('s_value', $row)) return (string)$row['s_value'];
    return $default;
}

function setting_set(string $key, ?string $value): void {
    $pdo = pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now = date('Y-m-d H:i:s');
    if ($driver === 'mysql') {
        $stm = $pdo->prepare("INSERT INTO settings (s_key, s_value, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE s_value=VALUES(s_value), updated_at=VALUES(updated_at)");
        $stm->execute([$key, $value, $now]);
    } else {
        // sqlite upsert
        $stm = $pdo->prepare("INSERT INTO settings (s_key, s_value, updated_at) VALUES (?,?,?) ON CONFLICT(s_key) DO UPDATE SET s_value=excluded.s_value, updated_at=excluded.updated_at");
        $stm->execute([$key, $value, $now]);
    }
}

/**
 * Get timezone information (tzid and offset) based on latitude and longitude using timezoneapi.io.
 */
function get_timezone_info(float $lat, float $lng): array {
    $url = "https://timezoneapi.io/api/timezone/?lat={$lat}&lng={$lng}";
    $context = stream_context_create([
        'http' => [
            'header' => 'User-Agent: OpenParcelTracker',
            'timeout' => 5,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response) {
        $data = json_decode($response, true);
        if ($data && isset($data['data']['timezone']['id'])) {
            $tzid = $data['data']['timezone']['id'];
            $offset = $data['data']['timezone']['offset_sec'] ?? 0;
            $offset_str = sprintf('%+03d:%02d', intdiv($offset, 3600), abs($offset % 3600) / 60);
            return ['tzid' => $tzid, 'offset' => $offset_str];
        }
    }
    // Fallback to UTC
    return ['tzid' => 'UTC', 'offset' => '+00:00'];
}

/**
 * Create a new event/movement with proper timezone handling
 */
function create_movement_event(array $data): bool {
    $pdo = pdo();
    
    // Extract required fields
    $package_id = $data['package_id'] ?? null;
    $lat = $data['lat'] ?? null;
    $lng = $data['lng'] ?? null;
    
    if (!$package_id || $lat === null || $lng === null) {
        return false;
    }
    
    // Get timezone info
    $tz_info = get_timezone_info((float)$lat, (float)$lng);
    
    // Use provided datetime or current time
    $event_dt_utc = $data['event_dt_utc'] ?? gmdate('Y-m-d H:i:s');
    
    // Calculate local time
    try {
        $utc_dt = new DateTime($event_dt_utc, new DateTimeZone('UTC'));
        $local_tz = new DateTimeZone($tz_info['tzid']);
        $local_dt = $utc_dt->setTimezone($local_tz);
        $event_dt_local = $local_dt->format('Y-m-d H:i:s');
        $utc_offset = $local_dt->format('P');
    } catch (Exception $e) {
        // Fallback if timezone conversion fails
        $event_dt_local = $event_dt_utc;
        $utc_offset = '+00:00';
    }
    
    // Prepare SQL
    $sql = "INSERT INTO locations (
        package_id, lat, lng, address, note, source, is_manual,
        event_dt_utc, tzid, utc_offset, event_dt_local,
        title, message, from_city, from_state, from_country_code,
        to_city, to_state, to_country_code, facility_name, postal_code,
        event_code, status_code, carrier, piece_id, sequence, created_by,
        created_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?
    )";
    
    $stm = $pdo->prepare($sql);
    return $stm->execute([
        $package_id,
        (float)$lat,
        (float)$lng,
        $data['address'] ?? null,
        $data['note'] ?? 'Movement recorded',
        $data['source'] ?? 'manual',
        $data['is_manual'] ?? 1,
        $event_dt_utc,
        $tz_info['tzid'],
        $utc_offset,
        $event_dt_local,
        $data['title'] ?? null,
        $data['message'] ?? null,
        $data['from_city'] ?? null,
        $data['from_state'] ?? null,
        $data['from_country_code'] ?? null,
        $data['to_city'] ?? null,
        $data['to_state'] ?? null,
        $data['to_country_code'] ?? null,
        $data['facility_name'] ?? null,
        $data['postal_code'] ?? null,
        $data['event_code'] ?? null,
        $data['status_code'] ?? null,
        $data['carrier'] ?? null,
        $data['piece_id'] ?? null,
        $data['sequence'] ?? 0,
        $data['created_by'] ?? null,
        date('Y-m-d H:i:s')
    ]);
}

/**
 * Get movement history with grouping options
 */
function get_movement_history(int $package_id, string $group_by = 'country,date'): array {
    $pdo = pdo();
    
    $sql = "SELECT * FROM locations 
            WHERE package_id = ? 
            ORDER BY event_dt_local DESC, sequence DESC, created_at DESC";
    
    $stm = $pdo->prepare($sql);
    $stm->execute([$package_id]);
    $movements = $stm->fetchAll();
    
    if (empty($movements)) {
        return [];
    }
    
    // Group movements
    $groups = [];
    foreach ($movements as $movement) {
        $country = $movement['to_country_code'] ?? 'Unknown';
        $date = $movement['event_dt_local'] ? date('Y-m-d', strtotime($movement['event_dt_local'])) : 'Unknown';
        
        if ($group_by === 'date,country') {
            $key = $date . '|' . $country;
            $groups[$key]['label'] = format_date_country_label($date, $country);
        } else {
            $key = $country . '|' . $date;
            $groups[$key]['label'] = format_country_date_label($country, $date);
        }
        
        $groups[$key]['movements'][] = $movement;
    }
    
    return $groups;
}

/**
 * Format country-date label for DHL-style display
 */
function format_country_date_label(string $country, string $date): string {
    $country_names = [
        'US' => 'United States',
        'GB' => 'United Kingdom', 
        'DE' => 'Germany',
        'FR' => 'France',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'AT' => 'Austria',
        'CH' => 'Switzerland',
        'UA' => 'Ukraine',
        'PL' => 'Poland',
        'CZ' => 'Czech Republic',
        'HU' => 'Hungary',
        'RO' => 'Romania',
        'BG' => 'Bulgaria',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'HR' => 'Croatia',
        'RS' => 'Serbia',
        'BA' => 'Bosnia and Herzegovina',
        'AL' => 'Albania',
        'MK' => 'North Macedonia',
        'ME' => 'Montenegro',
        'XK' => 'Kosovo'
    ];
    
    $country_name = $country_names[$country] ?? $country;
    
    if ($date === 'Unknown') {
        return $country_name;
    }
    
    $timestamp = strtotime($date);
    if ($timestamp) {
        $formatted_date = date('l, j F Y', $timestamp);
        return $country_name . ' — ' . $formatted_date;
    }
    
    return $country_name . ' — ' . $date;
}

/**
 * Format date-country label for DHL-style display
 */
function format_date_country_label(string $date, string $country): string {
    if ($date === 'Unknown') {
        return 'Unknown Date';
    }
    
    $timestamp = strtotime($date);
    if ($timestamp) {
        return date('l, j F Y', $timestamp);
    }
    
    return $date;
}
