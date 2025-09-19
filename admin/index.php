<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../db.php';

/**
 * Admin panel:
 * - Login (username / password)
 * - Show all packages on a map (draggable markers)
 * - Add new packages
 * - Update package position by drag or by address (client geocodes)
 * - History per package
 * - All AJAX endpoints in this file
 */

// Security helpers for photo uploads
function ensure_photos_dir(): void {
    $dir = __DIR__ . '/../photos';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    // Harden directory on Apache (no PHP/CGI execution). Harmless on other servers.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Options -ExecCGI\nRemoveHandler .php .phtml .phar .cgi .fcgi .pl\nphp_flag engine off\n");
    }
    // Also add a minimal index.html to deter listing on servers where directory indexes are enabled
    $idx = $dir . '/index.html';
    if (!file_exists($idx)) {
        @file_put_contents($idx, "<!doctype html><meta charset=\"utf-8\"><title>403</title>Access denied");
    }
}

/**
 * Validate and move uploaded image into photos/ under a sanitized base name.
 * @param array $file  One of $_FILES[...] entries
 * @param string $basename  Target filename base (without extension)
 * @param string|null $oldPath Previously saved image_path (to clean up if extension changes)
 * @return array { ok:bool, path?:string, error?:string }
 */
function handle_image_upload(array $file, string $basename, ?string $oldPath = null): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error'];
    }
    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if (!isset($file['size']) || (int)$file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'Image too large (max 5MB)'];
    }
    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload'];
    }

    // Sniff MIME using finfo (if available)
    $mimeSniffed = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mimeSniffed = (string)$fi->file($tmp);
    }

    // Basic image validation + dimensions
    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'error' => 'Not an image'];
    }
    $mimeFromImg = (string)($info['mime'] ?? '');

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $mime = $mimeSniffed ?: $mimeFromImg;
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Unsupported image type'];
    }

    // Constrain pixel dimensions / megapixels to prevent abuse
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    if ($w <= 0 || $h <= 0) {
        return ['ok' => false, 'error' => 'Corrupt image'];
    }
    $maxSide = 10000; // max dimension per side
    $maxMP   = 40;    // max megapixels
    if ($w > $maxSide || $h > $maxSide || ($w * $h) > ($maxMP * 1000000)) {
        return ['ok' => false, 'error' => 'Image dimensions too large'];
    }

    $ext = $allowed[$mime];

    // Sanitize basename (use tracking number, keep letters/digits/dash/underscore only)
    $base = preg_replace('~[^A-Za-z0-9._-]+~', '_', $basename) ?: 'img';

    ensure_photos_dir();
    $dir = __DIR__ . '/../photos/';
    $target = $dir . $base . '.' . $ext;

    if (!@move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'error' => 'Failed to store image'];
    }
    // Ensure reasonable file permissions
    @chmod($target, 0644);

    // Best effort: remove old file if extension changed
    if ($oldPath) {
        $oldRel = ltrim((string)$oldPath, '/');
        if (strpos($oldRel, 'photos/') === 0) {
            $oldAbs = __DIR__ . '/../' . $oldRel;
            if (is_file($oldAbs) && realpath(dirname($oldAbs)) === realpath($dir) && $oldAbs !== $target) {
                @unlink($oldAbs);
            }
        }
    }

    return ['ok' => true, 'path' => 'photos/' . basename($target)];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_logged_in(): bool {
    return isset($_SESSION['uid']) && (int)$_SESSION['uid'] > 0;
}

function require_login_json(): void {
    if (!is_logged_in()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
        exit;
    }
}

function checkVersion(): ?array {
    $url = 'https://raw.githubusercontent.com/ksanyok/OpenParcelTracker/main/db.php';
    $context = stream_context_create([
        'http' => [
            'header' => 'User-Agent: PHP',
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    if ($response) {
        // Extract VERSION from the file
        if (preg_match('/const VERSION = \'([^\']+)\'/', $response, $matches)) {
            $latest = $matches[1];
            $current = get_version();
            return [
                'latest' => $latest,
                'current' => $current,
                'update_available' => version_compare($latest, $current, '>')
            ];
        }
    }
    return null;
}

// Handle force update
if (isset($_GET['force_update'])) {
    $output = [];
    $returnVar = 0;
    exec('git pull origin main 2>&1', $output, $returnVar);
    if ($returnVar === 0) {
        $message = 'Updated successfully: ' . implode("\\n", $output);
    } else {
        $message = 'Update failed: ' . implode("\\n", $output);
    }
    echo "<script>alert('$message'); window.location.href = 'index.php';</script>";
    exit;
}

$pdo = pdo();

// Check if default password is still in use
$stm = $pdo->prepare("SELECT password_hash FROM users WHERE username = ?");
$stm->execute(['admin']);
$row = $stm->fetch();
$showDefault = $row && password_verify('admin123', $row['password_hash']);

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)$_POST['action'];

    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stm = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stm->execute([$username]);
        $row = $stm->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['uid'] = (int)$row['id'];
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Invalid credentials']);
        }
        exit;
    }

    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Protected actions
    require_login_json();

    if ($action === 'listPackages') {
        $q = trim((string)($_POST['q'] ?? ''));
        if ($q !== '') {
            $stm = $pdo->prepare("SELECT * FROM packages WHERE tracking_number LIKE ? OR title LIKE ? ORDER BY updated_at DESC");
            $stm->execute(['%'.$q.'%', '%'.$q.'%']);
        } else {
            $stm = $pdo->query("SELECT * FROM packages ORDER BY updated_at DESC");
        }
        $rows = $stm->fetchAll();
        echo json_encode(['ok'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'addPackage') {
        $tracking = trim((string)($_POST['tracking'] ?? ''));
        $title    = trim((string)($_POST['title'] ?? ''));
        // Robust parsing for lat/lng: ignore empty/"null"
        $lat = (isset($_POST['lat']) && $_POST['lat'] !== '' && strtolower((string)$_POST['lat']) !== 'null' && is_numeric($_POST['lat'])) ? (float)$_POST['lat'] : null;
        $lng = (isset($_POST['lng']) && $_POST['lng'] !== '' && strtolower((string)$_POST['lng']) !== 'null' && is_numeric($_POST['lng'])) ? (float)$_POST['lng'] : null;
        $address  = trim((string)($_POST['address'] ?? ''));
        $arriving = trim((string)($_POST['arriving'] ?? ''));
        $destination = trim((string)($_POST['destination'] ?? ''));
        $deliveryOption = trim((string)($_POST['delivery_option'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $imagePath = '';

        // Normalize accidental (0,0) coming from bad client values to "unknown"
        if ($lat === 0.0 && $lng === 0.0) { $lat = null; $lng = null; }

        // Handle Image Upload (validated)
        if (!empty($_FILES['newImage']['name'])) {
            $res = handle_image_upload($_FILES['newImage'], $tracking, null);
            if (!$res['ok']) { echo json_encode(['ok'=>false,'error'=>$res['error']]); exit; }
            $imagePath = $res['path'];
        }

        if ($tracking === '') {
            echo json_encode(['ok'=>false,'error'=>'Tracking number required']); exit;
        }

        // If no explicit coordinates provided, try to geocode initial point from address or arriving (start)
        if ($lat === null || $lng === null) {
            $geoQ = $address !== '' ? $address : ($arriving !== '' ? $arriving : '');
            if ($geoQ !== '') {
                $url = 'https://nominatim.openstreetmap.org/search?format=json&q=' . urlencode($geoQ);
                $ctx = stream_context_create(['http' => ['header' => "User-Agent: OpenParcelTracker\r\nAccept: application/json\r\n", 'timeout' => 6]]);
                $resp = @file_get_contents($url, false, $ctx);
                if ($resp) {
                    $arr = json_decode($resp, true);
                    if (is_array($arr) && count($arr) > 0) {
                        $lat = (float)$arr[0]['lat'];
                        $lng = (float)$arr[0]['lon'];
                        if ($address === '' && $arriving !== '') {
                            // prefer using human-entered arriving as last_address if address field was empty
                            $address = $arriving;
                        } elseif ($address === '') {
                            // fallback to display_name from geocoder
                            $address = (string)($arr[0]['display_name'] ?? '');
                        }
                    }
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $stm = $pdo->prepare("INSERT INTO packages (tracking_number, title, last_lat, last_lng, last_address, status, image_path, arriving, destination, delivery_option, description, created_at, updated_at)
                                  VALUES (?,?,?,?,?, 'created', ?, ?, ?, ?, ?, ?, ?)");
            $stm->execute([$tracking, $title, $lat, $lng, $address ?: null, $imagePath, $arriving, $destination, $deliveryOption, $description, $now, $now]);

            $pid = (int)$pdo->lastInsertId();

            // Add initial history entry with the current coordinates if available
            if ($lat !== null && $lng !== null) {
                $stm2 = $pdo->prepare("INSERT INTO locations (package_id, lat, lng, address, note, created_at)
                                       VALUES (?,?,?,?,?, ?)");
                $stm2->execute([$pid, $lat, $lng, $address ?: null, 'Created', $now]);
            }
            $pdo->commit();

            echo json_encode(['ok'=>true,'id'=>$pid]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'Duplicate tracking or DB error']);
        }
        exit;
    }

    if ($action === 'move') {
        $id      = (int)($_POST['id'] ?? 0);
        $lat     = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng     = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
        $address = trim((string)($_POST['address'] ?? ''));
        $note    = trim((string)($_POST['note'] ?? 'Moved'));

        if ($id <= 0 || $lat === null || $lng === null) {
            echo json_encode(['ok'=>false,'error'=>'Invalid params']); exit;
        }

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $stm = $pdo->prepare("UPDATE packages SET last_lat=?, last_lng=?, last_address=?, updated_at=? WHERE id=?");
            $stm->execute([$lat, $lng, $address ?: null, $now, $id]);

            $stm2 = $pdo->prepare("INSERT INTO locations (package_id, lat, lng, address, note, created_at)
                                   VALUES (?,?,?,?,?, ?)");
            $stm2->execute([$id, $lat, $lng, $address ?: null, $note ?: 'Moved', $now]);

            // Auto status update: in_transit or delivered if near destination
            $newStatus = 'in_transit';
            // fetch destination
            $stm3 = $pdo->prepare("SELECT destination FROM packages WHERE id = ?");
            $stm3->execute([$id]);
            $rowDest = $stm3->fetch();
            if ($rowDest && !empty($rowDest['destination'])) {
                $destQ = trim((string)$rowDest['destination']);
                // Geocode destination
                $url = 'https://nominatim.openstreetmap.org/search?format=json&q=' . urlencode($destQ);
                $ctx = stream_context_create(['http' => ['header' => "User-Agent: OpenParcelTracker\r\nAccept: application/json\r\n", 'timeout' => 5]]);
                $resp = @file_get_contents($url, false, $ctx);
                if ($resp) {
                    $arr = json_decode($resp, true);
                    if (is_array($arr) && count($arr) > 0) {
                        $dlat = (float)$arr[0]['lat'];
                        $dlng = (float)$arr[0]['lon'];
                        $toRad = fn($x)=>$x * M_PI / 180;
                        $R = 6371; // km
                        $dLat = $toRad($dlat - $lat);
                        $dLng = $toRad($dlng - $lng);
                        $a = sin($dLat/2)**2 + cos($toRad($lat)) * cos($toRad($dlat)) * sin($dLng/2)**2;
                        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
                        $distKm = $R * $c;
                        if ($distKm <= 5) { $newStatus = 'delivered'; }
                    }
                }
            }
            $stm4 = $pdo->prepare("UPDATE packages SET status=?, updated_at=? WHERE id=?");
            $stm4->execute([$newStatus, $now, $id]);

            $pdo->commit();

            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'DB error']);
        }
        exit;
    }

    if ($action === 'history') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }
        $stm = $pdo->prepare("SELECT id, lat, lng, address, note, created_at FROM locations WHERE package_id = ? ORDER BY created_at DESC");
        $stm->execute([$id]);
        echo json_encode(['ok'=>true,'data'=>$stm->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // New: add a note at current package position (does not move marker)
    if ($action === 'addNote') {
        $id = (int)($_POST['id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }
        if ($note === '') { echo json_encode(['ok'=>false,'error'=>'Note is required']); exit; }
        // Limit note length
        if (mb_strlen($note) > 500) { $note = mb_substr($note, 0, 500); }

        // Fetch current coords/address
        $stm = $pdo->prepare("SELECT last_lat, last_lng, last_address FROM packages WHERE id = ?");
        $stm->execute([$id]);
        $pkg = $stm->fetch();
        if (!$pkg) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $stm2 = $pdo->prepare("INSERT INTO locations (package_id, lat, lng, address, note, created_at) VALUES (?,?,?,?,?, ?)");
            $lat = isset($pkg['last_lat']) ? (float)$pkg['last_lat'] : null;
            $lng = isset($pkg['last_lng']) ? (float)$pkg['last_lng'] : null;
            $addr = $pkg['last_address'] ?? null;
            $stm2->execute([$id, $lat, $lng, $addr, $note, $now]);
            // Touch updated_at
            $stm3 = $pdo->prepare("UPDATE packages SET updated_at=? WHERE id=?");
            $stm3->execute([$now, $id]);
            $pdo->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'DB error']);
        }
        exit;
    }

    if ($action === 'getPackage') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }
        $stm = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
        $stm->execute([$id]);
        $row = $stm->fetch();
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
        echo json_encode(['ok'=>true,'data'=>$row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'updatePackage') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }

        // Fetch current
        $stm = $pdo->prepare("SELECT tracking_number, image_path FROM packages WHERE id = ?");
        $stm->execute([$id]);
        $cur = $stm->fetch();
        if (!$cur) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

        $title = trim((string)($_POST['title'] ?? ''));
        $arriving = trim((string)($_POST['arriving'] ?? ''));
        $destination = trim((string)($_POST['destination'] ?? ''));
        $deliveryOption = trim((string)($_POST['delivery_option'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $status = trim((string)($_POST['status'] ?? ''));

        $imagePath = $cur['image_path'] ?? '';
        if (!empty($_FILES['newImage']['name'])) {
            $res = handle_image_upload($_FILES['newImage'], (string)$cur['tracking_number'], $cur['image_path'] ?? null);
            if (!$res['ok']) { echo json_encode(['ok'=>false,'error'=>$res['error']]); exit; }
            $imagePath = $res['path'];
        }

        $now = date('Y-m-d H:i:s');
        $stm = $pdo->prepare("UPDATE packages SET title=?, arriving=?, destination=?, delivery_option=?, description=?, status=?, image_path=?, updated_at=? WHERE id=?");
        $stm->execute([$title ?: null, $arriving ?: null, $destination ?: null, $deliveryOption ?: null, $description ?: null, $status ?: null, $imagePath ?: null, $now, $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'update') {
        // Download and extract the repository
        $repoUrl = 'https://github.com/ksanyok/OpenParcelTracker/archive/refs/heads/main.zip';
        $zipFile = __DIR__ . '/../temp.zip';
        $extractDir = __DIR__ . '/../temp';

        // Clean up old files
        @unlink($zipFile);
        if (is_dir($extractDir)) {
            // Recursively delete temp dir if exists
            function delete_recursive($dir) {
                if (!is_dir($dir)) return;
                $files = scandir($dir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $path = $dir . '/' . $file;
                        is_dir($path) ? delete_recursive($path) : unlink($path);
                    }
                }
                rmdir($dir);
            }
            delete_recursive($extractDir);
        }

        // Download the zip
        $zipContent = file_get_contents($repoUrl);
        if ($zipContent === false) {
            echo json_encode(['ok' => false, 'error' => 'Failed to download repository zip.']);
            exit;
        }
        file_put_contents($zipFile, $zipContent);

        // Extract the zip
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === TRUE) {
            $zip->extractTo($extractDir);
            $zip->close();
        } else {
            unlink($zipFile);
            echo json_encode(['ok' => false, 'error' => 'Failed to extract repository zip.']);
            exit;
        }
        unlink($zipFile);

        // Find the extracted folder
        $extractedFolders = scandir($extractDir);
        $repoFolder = null;
        foreach ($extractedFolders as $folder) {
            if ($folder !== '.' && $folder !== '..' && is_dir($extractDir . '/' . $folder)) {
                $repoFolder = $extractDir . '/' . $folder;
                break;
            }
        }
        if (!$repoFolder) {
            echo json_encode(['ok' => false, 'error' => 'Failed to find extracted repository folder.']);
            exit;
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

        // Move files to root directory
        $files = scandir($repoFolder);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $src = $repoFolder . '/' . $file;
                $dst = __DIR__ . '/../' . $file;
                move_recursive($src, $dst);
            }
        }

        // Clean up
        rmdir($repoFolder);
        rmdir($extractDir);

        echo json_encode(['ok' => true, 'message' => 'Updated successfully.']);
        exit;
    }

    if ($action === 'changePassword') {
        $currentPassword = $_POST['currentPassword'] ?? '';
        $newPassword = $_POST['newPassword'] ?? '';
        $confirmPassword = $_POST['confirmPassword'] ?? '';

        if (empty($newPassword) || $newPassword !== $confirmPassword) {
            echo json_encode(['ok' => false, 'error' => 'New password and confirmation do not match.']);
            exit;
        }

        // Check current password
        $stm = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stm->execute([$_SESSION['uid']]);
        $row = $stm->fetch();
        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
            exit;
        }

        // Update password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stm = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stm->execute([$newHash, $_SESSION['uid']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'deletePackage') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }

        // Fetch image path before delete
        $stm0 = $pdo->prepare("SELECT image_path FROM packages WHERE id = ?");
        $stm0->execute([$id]);
        $cur = $stm0->fetch();
        $imgToDelete = $cur['image_path'] ?? null;

        $pdo->beginTransaction();
        try {
            // Delete locations first due to foreign key
            $stm = $pdo->prepare("DELETE FROM locations WHERE package_id = ?");
            $stm->execute([$id]);

            // Delete package
            $stm = $pdo->prepare("DELETE FROM packages WHERE id = ?");
            $stm->execute([$id]);

            $pdo->commit();

            // Best effort: remove image file on disk
            if ($imgToDelete) {
                $rel = ltrim((string)$imgToDelete, '/');
                if (strpos($rel, 'photos/') === 0) {
                    $abs = __DIR__ . '/../' . $rel;
                    if (is_file($abs)) { @unlink($abs); }
                }
            }

            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'Delete failed']);
        }
        exit;
    }

    // Crisp chat settings endpoints
    if ($action === 'getCrispSettings') {
        $data = [
            'enabled' => setting_get('crisp_enabled', '0') ?? '0',
            'website_id' => setting_get('crisp_website_id', '') ?? '',
            'sched_enabled' => setting_get('crisp_schedule_enabled', '0') ?? '0',
            'days' => setting_get('crisp_days', '1,2,3,4,5') ?? '1,2,3,4,5',
            'start' => setting_get('crisp_hours_start', '09:00') ?? '09:00',
            'end' => setting_get('crisp_hours_end', '18:00') ?? '18:00',
        ];
        echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Map settings endpoints: auto-zoom toggle in admin
    if ($action === 'getMapSettings') {
        $data = [
            'auto_zoom' => setting_get('admin_auto_zoom', '1') ?? '1',
        ];
        echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'saveMapSettings') {
        $auto = isset($_POST['auto_zoom']) && $_POST['auto_zoom'] === '1' ? '1' : '0';
        setting_set('admin_auto_zoom', $auto);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'saveCrispSettings') {
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0';
        $website_id = trim((string)($_POST['website_id'] ?? ''));
        // Basic validation for UUID-like id
        if ($website_id !== '' && !preg_match('~^[A-Za-z0-9-]{8,}$~', $website_id)) {
            echo json_encode(['ok'=>false,'error'=>'Invalid Website ID']);
            exit;
        }
        $sched_enabled = isset($_POST['sched_enabled']) && $_POST['sched_enabled'] === '1' ? '1' : '0';
        $days = preg_replace('~[^0-6,]+~', '', (string)($_POST['days'] ?? '1,2,3,4,5'));
        // normalize days list (unique, sorted)
        $arrDays = array_values(array_unique(array_filter(array_map('intval', explode(',', $days)), function($d){ return $d>=0 && $d<=6; })));
        sort($arrDays);
        $daysNorm = implode(',', $arrDays ?: [1,2,3,4,5]);
        $start = (string)($_POST['start'] ?? '09:00');
        $end = (string)($_POST['end'] ?? '18:00');
        if (!preg_match('~^\d{2}:\d{2}$~', $start)) $start = '09:00';
        if (!preg_match('~^\d{2}:\d{2}$~', $end)) $end = '18:00';

        setting_set('crisp_enabled', $enabled);
        setting_set('crisp_website_id', $website_id);
        setting_set('crisp_schedule_enabled', $sched_enabled);
        setting_set('crisp_days', $daysNorm);
        setting_set('crisp_hours_start', $start);
        setting_set('crisp_hours_end', $end);

        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
    exit;
}

$logged = is_logged_in();
$version_info = $logged ? checkVersion() : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin • Package Tracker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#D40511">
<link rel="icon" type="image/png" href="../dhl.png">
<link rel="apple-touch-icon" href="../dhl.png">
<link rel="shortcut icon" href="../dhl.png">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
  :root{ --brand-yellow:#FFCC00; --brand-red:#D40511; --brand-red-dark:#b1040e; --bg:#fff; --card:rgba(255,255,255,0.92); --text:#0b0b0b; --muted:#5b6470; --border:rgba(0,0,0,0.06); --primary: var(--brand-red); --primary-2: var(--brand-red-dark); --shadow:0 20px 40px rgba(212,5,17,.08); }
  *{box-sizing:border-box}
  body{margin:0; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; flex-direction:column; padding-bottom: 96px;}
  /* Background + blobs */
  .bg-animated{ position:fixed; inset:0; overflow:hidden; z-index:0; pointer-events:none; background: radial-gradient(60vw 60vw at -10% -20%, rgba(255,204,0,0.35), rgba(255,204,0,0) 60%), radial-gradient(50vw 50vw at 110% 10%, rgba(212,5,17,0.25), rgba(212,5,17,0) 60%); filter: saturate(115%); }
  .bg-animated::before, .bg-animated::after{ content:""; position:absolute; inset:-20%; background: conic-gradient(from 0deg, rgba(255,204,0,0.20), rgba(212,5,17,0.15), rgba(255,204,0,0.20)); animation: swirl 28s linear infinite; mix-blend-mode: multiply; }
  .bg-animated::after{ animation-duration: 44s; animation-direction: reverse; opacity:.6 }
  .bg-animated .blob{ position:absolute; width:38vmin; height:38vmin; border-radius:50%; filter: blur(20px); opacity:.55; background: radial-gradient(circle at 30% 30%, rgba(255,204,0,0.9), rgba(255,204,0,0.2) 60%); animation: float 18s ease-in-out infinite; }
  .bg-animated .b1{ left:8%; top:12%; }
  .bg-animated .b2{ right:10%; top:20%; background: radial-gradient(circle at 30% 30%, rgba(212,5,17,0.8), rgba(212,5,17,0.15) 60%); animation-duration: 22s; animation-delay: -4s; }
  .bg-animated .b3{ left:20%; bottom:10%; width:46vmin; height:46vmin; background: radial-gradient(circle at 30% 30%, rgba(255,204,0,0.7), rgba(212,5,17,0.18)); animation-duration: 26s; animation-delay: -8s; }
  @keyframes swirl{ to { transform: rotate(1turn); } }
  @keyframes float{ 0%,100%{ transform: translate3d(0,0,0) scale(1); } 50%{ transform: translate3d(0,-6%,0) scale(1.08); } }

  header{position:relative; z-index:1; padding:16px 20px; background:linear-gradient(90deg, var(--brand-red) 0%, #e50f1b 35%, var(--brand-yellow) 100%); color:#fff; display:flex; align-items:center; justify-content:space-between;}
  main{position:relative; z-index:1; padding:20px; max-width:1200px; margin:0 auto; width:100%;}
  .card{background:rgba(255,255,255,0.55); border:1px solid rgba(255,255,255,0.45); border-radius:18px; padding:16px; box-shadow:var(--shadow); backdrop-filter: blur(12px) saturate(120%);} 
  input, button, textarea{font:inherit;}
  input[type=text], input[type=password]{padding:12px 14px; border:1px solid var(--border); border-radius:12px; outline:none; transition:.2s border-color, .2s box-shadow; background:rgba(255,255,255,0.8);} 
  input[type=text]:focus, input[type=password]:focus, textarea:focus{border-color: var(--brand-red); box-shadow:0 0 0 3px rgba(212,5,17,0.15)}
  /* Icon inputs */
  .input-wrap{display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border); border-radius:12px; background:rgba(255,255,255,0.66); backdrop-filter: blur(10px) saturate(120%); box-shadow: var(--shadow);}
  .input-wrap:focus-within{border-color: var(--brand-red); box-shadow:0 0 0 3px rgba(212,5,17,0.15)}
  .input-wrap i{color: var(--primary); font-size:18px}
  .input-wrap input.plain{border:0; outline:none; background:transparent; padding:0; min-width:160px}
  .textarea-wrap{display:flex; padding:12px; border:1px solid var(--border); border-radius:12px; background:rgba(255,255,255,0.66); backdrop-filter: blur(10px) saturate(120%); box-shadow: var(--shadow);}
  .textarea-wrap textarea{border:0; outline:none; background:transparent; width:100%; padding:0; resize:vertical; min-height:90px}

  button{padding:12px 14px; border:0; border-radius:12px; background:linear-gradient(180deg, var(--primary), var(--primary-2)); color:#fff; cursor:pointer; box-shadow:0 6px 14px rgba(212,5,17,.25); display:inline-flex; align-items:center; gap:8px}
  button:hover{transform: translateY(-1px); box-shadow:0 10px 20px rgba(212,5,17,.3)}
  #map{height:520px; border-radius:16px; border:1px solid rgba(255,255,255,0.45); box-shadow:var(--shadow);} 
  .grid{display:grid; gap:16px;}
  .grid-2{grid-template-columns: 1fr 1fr;}
  .row{display:flex; gap:12px; align-items:center; flex-wrap:wrap;}
  .muted{color:var(--muted);} 
  table{width:100%; border-collapse:collapse; font-size:14px; background:transparent;}
  th, td{padding:8px 10px; border-bottom:1px solid var(--border); text-align:left;}
  .badge{display:inline-block; padding:2px 8px; border-radius:999px; background:linear-gradient(180deg, #fff 0%, #ffe680 100%); border:1px solid #ffd34d; color:#553300; font-size:12px;}
  .list{max-height:420px; overflow:auto;}
  .link{color:var(--primary); text-decoration:underline; cursor:pointer;}
  .right{margin-left:auto;}
  .hint{font-size:12px; color:var(--muted);} 
  .btn-small{ padding:8px 10px; border-radius:10px; font-size:12px; box-shadow:0 3px 8px rgba(212,5,17,.18) }
  .btn-ghost{ background:#fff; color:#111; box-shadow:none; border:1px solid var(--border); }
  .pick-hint{ font-size:12px; color:var(--muted); margin-left:6px; }
  .input-wrap.picking{ border-color: var(--brand-red); box-shadow:0 0 0 3px rgba(212,5,17,0.15); }

  @media (max-width: 960px){ .grid-2{grid-template-columns: 1fr;} }

  /* Modal edit dialog */
  .modal{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,0.35); z-index:1000; padding:16px }
  .modal.show{ display:grid }
  .modal-dialog{ width:min(760px,96vw); max-height:calc(100vh - 120px); overflow:auto; }
  @media (max-width: 640px){ .modal-dialog{ max-height:calc(100vh - 40px); } }
</style>
</head>
<body>
<div class="bg-animated" aria-hidden="true"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>
<header>
  <div style="display:flex; align-items:center; gap:10px;">
    <a href="../index.php" title="Open client" style="display:inline-flex; align-items:center; gap:8px; background:#fff; border-radius:12px; padding:6px 10px; box-shadow:0 6px 14px rgba(0,0,0,0.15);">
      <img src="../dhl-logo.svg" alt="Logo" style="height:18px; width:auto; display:block; filter:saturate(110%);"/>
    </a>
    <strong style="display:flex; align-items:center; gap:8px;"><i class="ri-settings-3-line"></i> Admin • Package Tracker</strong>
  </div>
  <?php if ($logged): ?>
    <div>
      <?php if ($version_info && $version_info['update_available']): ?>
        <button id="updateBtn" title="Update from repository"><i class="ri-download-cloud-2-line"></i> Update to <?php echo h($version_info['latest']); ?></button>
        <a href="?force_update=1" style="margin-left:10px; color: #111; background:#ffeb99; padding:6px 10px; border-radius:10px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;"><i class="ri-flashlight-fill"></i>(force)</a>
      <?php endif; ?>
      <button id="logoutBtn" title="Log out"><i class="ri-logout-box-r-line"></i> Log out</button>
    </div>
  <?php endif; ?>
</header>
<main>
<?php if (!$logged): ?>
  <div class="card" style="max-width:480px; margin:40px auto;">
    <h2 style="display:flex; align-items:center; gap:8px;"><i class="ri-shield-user-line"></i> Sign in</h2>
    <form id="loginForm" class="grid" onsubmit="return false;">
      <label>Username
        <div class="input-wrap"><i class="ri-user-3-line"></i><input type="text" id="u" value="admin" autocomplete="username" class="plain"></div>
      </label>
      <label>Password
        <div class="input-wrap"><i class="ri-lock-2-line"></i><input type="password" id="p" value="admin123" autocomplete="current-password" class="plain"></div>
      </label>
      <button id="loginBtn"><i class="ri-login-box-line"></i> Sign in</button>
      <?php if ($showDefault): ?>
        <p class="hint">Default credentials are <code>admin / admin123</code> (auto-created on first run). Change ASAP.</p>
      <?php endif; ?>
    </form>
  </div>
  <script>
    const loginBtn = document.getElementById('loginBtn');
    loginBtn?.addEventListener('click', async ()=>{
      const fd = new FormData();
      fd.append('action','login');
      fd.append('username', document.getElementById('u').value);
      fd.append('password', document.getElementById('p').value);
      const r = await fetch('', {method:'POST', body:fd});
      const j = await r.json();
      if(j.ok){ location.reload(); } else { alert(j.error || 'Login failed'); }
    });
  </script>
<?php else: ?>
  <div class="grid grid-2">
    <div class="card">
      <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-map-pin-line"></i> Map</h3>
      <div id="map"></div>
      <div class="row" style="margin-top:8px; align-items:center; gap:12px;">
        <label class="row" style="gap:8px; align-items:center;">
          <input type="checkbox" id="autoZoomToggle"> Auto-zoom
        </label>
        <span class="hint">Disable to prevent map from refitting when markers move or list refreshes.</span>
      </div>
      <p class="hint" style="margin-top:8px;">Tip: drag a marker to move a package. Click a marker to set by address or view mini-info.</p>
    </div>
    <div class="card">
      <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-archive-2-line"></i> Packages</h3>
      <div class="row">
        <div class="input-wrap" style="flex:1 1 auto;"><i class="ri-search-line"></i><input type="text" id="search" placeholder="Filter by tracking or title…" class="plain" style="width:100%;"></div>
        <button id="refreshBtn"><i class="ri-refresh-line"></i> Refresh</button>
      </div>
      <div class="list" style="margin-top:12px;">
        <table id="tbl">
          <thead>
            <tr>
              <th>Tracking #</th>
              <th>Title</th>
              <th>Coords</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <hr style="margin:16px 0; border:0; border-top:1px solid var(--border);">

      <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-box-3-line"></i> New package</h3>
      <form id="addForm" class="grid" enctype="multipart/form-data" onsubmit="return false;">
        <div class="row">
          <div class="input-wrap"><i class="ri-hashtag"></i><input type="text" id="newTracking" placeholder="Tracking number *" class="plain" required></div>
          <div class="input-wrap"><i class="ri-edit-line"></i><input type="text" id="newTitle" placeholder="Title (optional)" class="plain"></div>
        </div>
        <div class="row">
          <div class="input-wrap" style="flex:1 1 auto;"><i class="ri-map-pin-line"></i><input type="text" id="newAddress" placeholder="Initial address (optional – will be geocoded)" class="plain" style="width:100%;"></div>
          <div class="input-wrap"><i class="ri-send-plane-line"></i><input type="text" id="newArriving" placeholder="Arriving (start)" class="plain"></div>
          <button type="button" id="newArrPickBtn" class="btn-small"><i class="ri-focus-2-line"></i> Pick start</button>
        </div>
        <div class="row">
          <div class="input-wrap"><i class="ri-flag-line"></i><input type="text" id="newDestination" placeholder="Destination (end)" class="plain"></div>
          <button type="button" id="newDestPickBtn" class="btn-small"><i class="ri-focus-2-line"></i> Pick destination</button>
          <div class="pick-hint">You can set start/end by address or pick on the map.</div>
        </div>
        <div class="row" style="align-items:flex-start;">
          <div class="textarea-wrap" style="flex:1 1 320px;"><textarea id="newDescription" placeholder="Images and Description"></textarea></div>
          <div style="display:flex; align-items:center; gap:10px;">
            <input type="file" id="newImage" accept="image/*">
            <button id="addBtn" type="button"><i class="ri-add-circle-line"></i> Create</button>
          </div>
        </div>
        <p class="hint">If address is provided, it will be geocoded via Nominatim and used as initial coordinates.</p>
      </form>

      <div id="historyBox" style="margin-top:16px; display:none;">
        <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-time-line"></i> History: <span id="histTitle"></span>
          <button id="histAddNoteBtn" class="btn-small" style="margin-left:auto;"><i class="ri-sticky-note-add-line"></i> Add note</button>
        </h3>
        <div class="list">
          <table id="histTbl">
            <thead><tr><th>Date</th><th>Coords</th><th>Address</th><th>Note</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <hr style="margin:16px 0; border:0; border-top:1px solid var(--border);">

      <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-shield-keyhole-line"></i> Change Password</h3>
      <form id="changePasswordForm" class="grid">
        <div class="input-wrap"><i class="ri-lock-unlock-line"></i><input type="password" id="currentPassword" placeholder="Current password" class="plain" required></div>
        <div class="input-wrap"><i class="ri-key-2-line"></i><input type="password" id="newPassword" placeholder="New password" class="plain" required></div>
        <div class="input-wrap"><i class="ri-key-line"></i><input type="password" id="confirmPassword" placeholder="Confirm new password" class="plain" required></div>
        <button id="changePasswordBtn"><i class="ri-shield-check-line"></i> Change Password</button>
      </form>

      <hr style="margin:16px 0; border:0; border-top:1px solid var(--border);">

      <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-chat-1-line"></i> Live chat (Crisp)</h3>
      <form id="crispForm" class="grid" onsubmit="return false;">
        <div class="row">
          <label class="row" style="gap:8px; align-items:center;">
            <input type="checkbox" id="crispEnabled"> Enable Crisp chat
          </label>
          <div class="input-wrap" style="flex:1 1 260px;"><i class="ri-key-2-line"></i><input type="text" id="crispWebsiteId" placeholder="Crisp Website ID (UUID)" class="plain" style="width:100%"></div>
        </div>
        <div class="row">
          <label class="row" style="gap:8px; align-items:center;">
            <input type="checkbox" id="crispSchedEnabled"> Enable schedule (show chat only in selected time)
          </label>
        </div>
        <div class="row" style="flex-wrap:wrap; gap:10px 16px;">
          <div class="row" style="gap:8px; align-items:center;">
            <strong class="muted" style="min-width:80px;">Days:</strong>
            <label class="row" style="gap:6px; align-items:center;"><input type="checkbox" class="crispDay" value="1"> Mon</label>
            <label class="row" style="gap:6px; align-items:center;"><input type="checkbox" class="crispDay" value="2"> Tue</label>
            <label class="row" style="gap:6px; align-items:center;"><input type="checkbox" class="crispDay" value="3"> Wed</label>
            <label class="row" style="gap:6px; align-items:center;"><input type="checkbox" class="crispDay" value="4"> Thu</label>
            <label class="row" style="gap:6px; align-items:center;"><input type="checkbox" class="crispDay" value="5"> Fri</label>
            <label class="row" style="gap:6px; align-items:center;"><input type="checkbox" class="crispDay" value="6"> Sat</label>
            <label class="row" style="gap:6px; align-items:center;"><input type="checkbox" class="crispDay" value="0"> Sun</label>
          </div>
          <div class="row" style="gap:8px; align-items:center;">
            <strong class="muted">Hours:</strong>
            <input type="time" id="crispStart" value="09:00" class="plain" style="padding:8px 10px; border-radius:10px; border:1px solid var(--border);">
            <span class="muted">–</span>
            <input type="time" id="crispEnd" value="18:00" class="plain" style="padding:8px 10px; border-radius:10px; border:1px solid var(--border);">
            <span class="hint">Client browser local time. Overnight ranges supported.</span>
          </div>
        </div>
        <div class="row">
          <button id="crispSaveBtn"><i class="ri-save-3-line"></i> Save chat settings</button>
        </div>
      </form>

      <!-- Edit Package Modal moved to end of body -->
    </div>
  </div>

  <script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin="">
  </script>
  <script>
    const $ = sel => document.querySelector(sel);
    const $$ = sel => document.querySelectorAll(sel);

    async function post(action, payload = {}) {
      const fd = new FormData();
      fd.append('action', action);
      for (const [k,v] of Object.entries(payload)) fd.append(k, v ?? '');
      const r = await fetch('', { method:'POST', body: fd });
      return await r.json();
    }

    // Logout
    document.getElementById('logoutBtn')?.addEventListener('click', async ()=>{
      await post('logout');
      location.href = location.href;
    });

    // Update
    document.getElementById('updateBtn')?.addEventListener('click', async ()=>{
      if (!confirm('Are you sure you want to update from the repository? This may overwrite local changes.')) return;
      const j = await post('update');
      if (j.ok) {
        alert('Updated successfully!');
        location.reload();
      } else {
        alert('Update failed: ' + j.error);
      }
    });

    // Leaflet map
    const map = L.map('map');
    const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    map.setView([31.0461, 34.8516], 7);

    // Auto-zoom setting
    let autoZoomEnabled = true;
    async function loadMapSettings(){
      try{
        const j = await post('getMapSettings');
        if(j.ok){
          autoZoomEnabled = (j.data.auto_zoom === '1');
          const cb = document.getElementById('autoZoomToggle');
          if (cb) cb.checked = autoZoomEnabled;
        }
      }catch{}
    }
    loadMapSettings();
    document.getElementById('autoZoomToggle')?.addEventListener('change', async (e)=>{
      autoZoomEnabled = !!e.target.checked;
      await post('saveMapSettings', { auto_zoom: autoZoomEnabled ? '1' : '0' });
    });

    // Picking Start/Destination on map
    const pickState = { active:false, target:null, ctx:null };
    let pickStartMarker = null, pickDestMarker = null, pickRoute = null;
    const startIconPick = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#16a34a;border:2px solid #0f7a37;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});
    const destIconPick  = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#ef4444;border:2px solid #b91c1c;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});

    function targetInputSelector(){
      if (!pickState.target || !pickState.ctx) return null;
      if (pickState.ctx === 'new') return pickState.target === 'start' ? '#newArriving' : '#newDestination';
      return pickState.target === 'start' ? '#editArriving' : '#editDestination';
    }
    function applyPickHighlight(on){
      const sel = targetInputSelector();
      if (!sel) return;
      const wrap = document.querySelector(sel)?.closest('.input-wrap');
      if (wrap) wrap.classList.toggle('picking', !!on);
    }
    function setPickMode(target, ctx){
      pickState.active = true; pickState.target = target; pickState.ctx = ctx;
      map.getContainer().style.cursor = 'crosshair';
      applyPickHighlight(true);
    }
    function leavePickMode(){
      applyPickHighlight(false);
      pickState.active=false; pickState.target=null; pickState.ctx=null;
      map.getContainer().style.cursor='';
    }

    async function reverseGeocode(lat, lng){
      try{
        const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}`;
        const r = await fetch(url, { headers: { 'Accept':'application/json' }});
        const j = await r.json();
        return (j && (j.display_name || (j.address && (j.address.city || j.address.town || j.address.village)))) || `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
      }catch{ return `${lat.toFixed(6)}, ${lng.toFixed(6)}`; }
    }

    function updatePickPreview(){
      if (pickRoute){ map.removeLayer(pickRoute); pickRoute=null; }
      const pts=[];
      if (pickStartMarker) pts.push(pickStartMarker.getLatLng());
      if (pickDestMarker)  pts.push(pickDestMarker.getLatLng());
      if (pts.length===2){ pickRoute = L.polyline(pts, { color:'#111', weight:3, opacity:0.6, dashArray:'6 6' }).addTo(map); }
    }

    async function handlePick(latlng){
      if (pickState.target === 'start'){
        if (!pickStartMarker){
          pickStartMarker = L.marker(latlng, { draggable:true, icon:startIconPick }).addTo(map);
          pickStartMarker.on('dragend', async ()=>{
            const ll = pickStartMarker.getLatLng();
            const addr = await reverseGeocode(ll.lat, ll.lng);
            if (pickState.ctx === 'new') $('#newArriving').value = addr; else $('#editArriving').value = addr;
            updatePickPreview();
          });
        } else { pickStartMarker.setLatLng(latlng); }
        const addr = await reverseGeocode(latlng.lat, latlng.lng);
        if (pickState.ctx === 'new') $('#newArriving').value = addr; else $('#editArriving').value = addr;
      } else if (pickState.target === 'dest'){
        if (!pickDestMarker){
          pickDestMarker = L.marker(latlng, { draggable:true, icon:destIconPick }).addTo(map);
          pickDestMarker.on('dragend', async ()=>{
            const ll = pickDestMarker.getLatLng();
            const addr = await reverseGeocode(ll.lat, ll.lng);
            if (pickState.ctx === 'new') $('#newDestination').value = addr; else $('#editDestination').value = addr;
            updatePickPreview();
          });
        } else { pickDestMarker.setLatLng(latlng); }
        const addr = await reverseGeocode(latlng.lat, latlng.lng);
        if (pickState.ctx === 'new') $('#newDestination').value = addr; else $('#editDestination').value = addr;
      }
      updatePickPreview();
      leavePickMode();
    }

    // New: if an address is already entered, geocode it and place/move the marker automatically
    async function tryPlaceMarkerFromAddress(target, ctx){
      const sel = (ctx === 'new')
        ? (target === 'start' ? '#newArriving' : '#newDestination')
        : (target === 'start' ? '#editArriving' : '#editDestination');
      const inp = document.querySelector(sel);
      const val = (inp?.value || '').trim();
      if (!val) return false;
      const ll = await geocode(val);
      if (!ll) return false;
      const latlng = { lat: ll[0], lng: ll[1] };

      if (target === 'start') {
        if (!pickStartMarker){
          pickStartMarker = L.marker(latlng, { draggable:true, icon:startIconPick }).addTo(map);
          pickStartMarker.on('dragend', async ()=>{
            const ll2 = pickStartMarker.getLatLng();
            const addr = await reverseGeocode(ll2.lat, ll2.lng);
            if (inp) inp.value = addr;
            updatePickPreview();
          });
        } else { pickStartMarker.setLatLng(latlng); }
      } else {
        if (!pickDestMarker){
          pickDestMarker = L.marker(latlng, { draggable:true, icon:destIconPick }).addTo(map);
          pickDestMarker.on('dragend', async ()=>{
            const ll2 = pickDestMarker.getLatLng();
            const addr = await reverseGeocode(ll2.lat, ll2.lng);
            if (inp) inp.value = addr;
            updatePickPreview();
          });
        } else { pickDestMarker.setLatLng(latlng); }
      }
      updatePickPreview();
      if (autoZoomEnabled) { map.flyTo(latlng, 13); }
      return true;
    }

    $('#newArrPickBtn')?.addEventListener('click', async (ev)=>{
      const btn = ev.currentTarget;
      if (btn) { btn.disabled = true; var oldHtml = btn.innerHTML; btn.innerHTML = '<i class="ri-compass-3-line"></i> Searching…'; }
      try{
        const ok = await tryPlaceMarkerFromAddress('start','new');
        if (!ok) setPickMode('start','new');
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = oldHtml; }
      }
    });
    $('#newDestPickBtn')?.addEventListener('click', async (ev)=>{
      const btn = ev.currentTarget;
      if (btn) { btn.disabled = true; var oldHtml = btn.innerHTML; btn.innerHTML = '<i class="ri-compass-3-line"></i> Searching…'; }
      try{
        const ok = await tryPlaceMarkerFromAddress('dest','new');
        if (!ok) setPickMode('dest','new');
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = oldHtml; }
      }
    });
    $('#editArrPickBtn')?.addEventListener('click', async (ev)=>{
      const btn = ev.currentTarget;
      if (btn) { btn.disabled = true; var oldHtml = btn.innerHTML; btn.innerHTML = '<i class="ri-compass-3-line"></i> Searching…'; }
      try{
        const ok = await tryPlaceMarkerFromAddress('start','edit');
        if (!ok) setPickMode('start','edit');
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = oldHtml; }
      }
    });
    $('#editDestPickBtn')?.addEventListener('click', async (ev)=>{
      const btn = ev.currentTarget;
      if (btn) { btn.disabled = true; var oldHtml = btn.innerHTML; btn.innerHTML = '<i class="ri-compass-3-line"></i> Searching…'; }
      try{
        const ok = await tryPlaceMarkerFromAddress('dest','edit');
        if (!ok) setPickMode('dest','edit');
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = oldHtml; }
      }
    });

    // Replace map click handler to support pick mode vs create temp marker
    let tempMarker = null;
    map.off('click');
    map.on('click', (e) => {
      if (pickState.active) { handlePick(e.latlng); return; }
      // Default behavior: new temp marker for quick create
      if (tempMarker) { map.removeLayer(tempMarker); tempMarker = null; }
      tempMarker = L.marker(e.latlng, { draggable: true }).addTo(map);
      const saveHtml = `
        <div>
          <strong>New package here?</strong><br>
          <button id="mkCreate">Create</button>
          <button id="mkCancel">Cancel</button>
          <div style="margin-top:6px;"><small>Drag to adjust before creating.</small></div>
        </div>`;
      tempMarker.bindPopup(saveHtml).openPopup();

      tempMarker.on('popupopen', () => {
        const mkCreate = document.getElementById('mkCreate');
        const mkCancel = document.getElementById('mkCancel');
        if (mkCreate) {
          mkCreate.onclick = async () => {
            const tracking = prompt('Tracking number (required):');
            if (!tracking) return;
            const title = prompt('Title (optional):') || '';
            const pos = tempMarker.getLatLng();
            const imageFile = document.getElementById('newImage')?.files?.[0];
            const formData = new FormData();
            formData.append('action', 'addPackage');
            formData.append('tracking', tracking);
            formData.append('title', title);
            formData.append('lat', pos.lat);
            formData.append('lng', pos.lng);
            formData.append('address', '');
            formData.append('arriving', document.getElementById('newArriving').value || '');
            formData.append('destination', document.getElementById('newDestination').value || '');
            formData.append('delivery_option', '');
            formData.append('description', '');
            if (imageFile) formData.append('newImage', imageFile);
            const j = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
            if (!j.ok) { alert(j.error || 'Create failed'); return; }
            map.removeLayer(tempMarker); tempMarker = null; await loadList();
          };
        }
        if (mkCancel) { mkCancel.onclick = () => { map.removeLayer(tempMarker); tempMarker = null; }; }
      });
    });

    function clearPickArtifacts(){
      if (pickStartMarker){ map.removeLayer(pickStartMarker); pickStartMarker=null; }
      if (pickDestMarker){ map.removeLayer(pickDestMarker); pickDestMarker=null; }
      if (pickRoute){ map.removeLayer(pickRoute); pickRoute=null; }
    }

    // When opening edit modal, optionally draw preview for existing addresses
    async function initEditPickPreview(d){
      try{
        clearPickArtifacts();
        const geocode = async (q)=>{
          if(!q) return null; const u='https://nominatim.openstreetmap.org/search?format=json&q='+encodeURIComponent(q);
          const r = await fetch(u, { headers:{'Accept':'application/json'} }); const a = await r.json(); if(!a.length) return null; return [parseFloat(a[0].lat), parseFloat(a[0].lon)];
        };
        const s = await geocode(d.arriving||'');
        const t = await geocode(d.destination||'');
        if (s){ pickStartMarker = L.marker(s, { draggable:true, icon:startIconPick }).addTo(map); pickStartMarker.on('dragend', async ()=>{ const ll=pickStartMarker.getLatLng(); $('#editArriving').value = await reverseGeocode(ll.lat,ll.lng); updatePickPreview(); }); }
        if (t){ pickDestMarker  = L.marker(t, { draggable:true, icon:destIconPick  }).addTo(map); pickDestMarker.on('dragend', async ()=>{ const ll=pickDestMarker.getLatLng();  $('#editDestination').value = await reverseGeocode(ll.lat,ll.lng); updatePickPreview(); }); }
        updatePickPreview();
      }catch{}
    }

    const markers = new Map(); // id -> marker
    let currentData = [];

    function markerPopupHtml(row) {
      const addr = row.last_address ? `<div><small>${row.last_address}</small></div>` : '';
      return `
        <div>
          <strong>${row.tracking_number}</strong>
          <div>${row.title ? row.title : ''}</div>
          ${addr}
          <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
            <button onclick="setByAddress(${row.id})">Set by address</button>
            <button onclick="addNote(${row.id})">Add note</button>
            <button onclick="showHistory(${row.id}, '${row.tracking_number.replace(/'/g, "\\'")}')">History</button>
            <button onclick="openEdit(${row.id})">Edit</button>
          </div>
        </div>
      `;
    }

    async function loadList() {
      const q = $('#search').value.trim();
      const j = await post('listPackages', { q });
      if (!j.ok) { alert(j.error || 'Failed to load'); return; }
      currentData = j.data || [];
      renderList(currentData);
      renderMarkers(currentData);
    }

    function renderList(rows) {
      const tbody = $('#tbl tbody');
      tbody.innerHTML = '';
      for (const r of rows) {
        const coords = (r.last_lat !== null && r.last_lng !== null) ? `${r.last_lat.toFixed(6)}, ${r.last_lng.toFixed(6)}` : '<span class="muted">—</span>';
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><span class="badge">${r.tracking_number}</span></td>
          <td>${r.title ? r.title : ''}</td>
          <td>${coords}</td>
          <td>${r.updated_at}</td>
          <td><span class="link" onclick="focusPkg(${r.id})">Focus</span> | <span class="link" onclick="openEdit(${r.id})">Edit</span> | <span class="link" onclick="addNote(${r.id})">Add note</span> | <span class="link" onclick="deletePkg(${r.id})">Delete</span></td>
        `;
        tbody.appendChild(tr);
      }
    }

    function renderMarkers(rows) {
      // remove olds that not in rows
      const ids = new Set(rows.map(r => r.id));
      for (const [id, m] of markers) {
        if (!ids.has(id)) {
          map.removeLayer(m);
          markers.delete(id);
        }
      }
      // create/update
      for (const r of rows) {
        if (r.last_lat === null || r.last_lng === null) continue;
        let m = markers.get(r.id);
        if (!m) {
          m = L.marker([r.last_lat, r.last_lng], { draggable: true }).addTo(map);
          m.on('dragend', async (ev)=>{
            const ll = ev.target.getLatLng();
            const ok = await movePackage(r.id, ll.lat, ll.lng, '');
            if (!ok) {
              // revert on fail
              ev.target.setLatLng([r.last_lat, r.last_lng]);
            } else {
              r.last_lat = ll.lat; r.last_lng = ll.lng; r.last_address = r.last_address || null;
              m.bindPopup(markerPopupHtml(r));
              await loadList();
            }
          });
          m.bindPopup(markerPopupHtml(r));
          markers.set(r.id, m);
        } else {
          m.setLatLng([r.last_lat, r.last_lng]);
          m.bindPopup(markerPopupHtml(r));
        }
      }
      if (rows.length > 0) {
        const pts = rows.filter(r=>r.last_lat!==null && r.last_lng!==null).map(r=>[r.last_lat, r.last_lng]);
        if (pts.length > 0) {
          if (autoZoomEnabled) { map.fitBounds(pts, { padding:[30,30] }); }
        }
      }
    }

    async function movePackage(id, lat, lng, address) {
      const formData = new FormData();
      formData.append('action', 'move');
      formData.append('id', id);
      formData.append('lat', lat);
      formData.append('lng', lng);
      formData.append('address', address);
      formData.append('note', 'Moved on map');

      const j = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
      if (!j.ok) { alert(j.error || 'Move failed'); return false; }
      return true;
    }

    // Set by address (client geocodes via Nominatim)
    window.setByAddress = async function(id){
      // Prefill with reverse-geocoded address from current marker position or last known address
      let defaultAddr = '';
      try {
        const m = markers.get(id);
        if (m) {
          const ll = m.getLatLng();
          defaultAddr = await reverseGeocode(ll.lat, ll.lng);
        } else {
          const r = currentData.find(x=>x.id===id);
          if (r) {
            if (r.last_address) defaultAddr = r.last_address;
            else if (r.last_lat !== null && r.last_lng !== null) defaultAddr = await reverseGeocode(r.last_lat, r.last_lng);
          }
        }
      } catch {}

      const addr = prompt('Enter address:', defaultAddr || '');
      if (!addr) return;
      try {
        const url = 'https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(addr);
        const r = await fetch(url, { headers: { 'Accept': 'application/json' }});
        const arr = await r.json();
        if (!arr.length) { alert('Address not found'); return; }
        const best = arr[0];
        const lat = parseFloat(best.lat), lng = parseFloat(best.lon);
        const ok = await movePackage(id, lat, lng, addr);
        if (ok) await loadList();
      } catch (e) {
        alert('Geocoding error');
      }
    }

    // Focus row
    window.focusPkg = async function(id){
      const r = currentData.find(x=>x.id===id);
      if (!r) return;
      // Load history first and draw focus route
      let hist = [];
      try{
        const fd = new FormData(); fd.append('action','history'); fd.append('id', String(id));
        const j = await fetch('', { method:'POST', body: fd }).then(r=>r.json());
        if (j.ok) hist = j.data || [];
      }catch{}
      await drawFocusRoute(r, hist);
      // Open marker popup and show history
      if (r.last_lat !== null && r.last_lng !== null) {
        const m = markers.get(id);
        if (m) { m.openPopup(); }
      }
      showHistory(id, r.tracking_number);
    }

    // Delete package
    window.deletePkg = async function(id){
      if (!confirm('Delete this package?')) return;
      const j = await post('deletePackage', {id});
      if (!j.ok) alert(j.error);
      else loadList();
    }

    // History
    window.showHistory = async function(id, title){
      const formData = new FormData();
      formData.append('action', 'history');
      formData.append('id', id);
      const j = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
      if (!j.ok) { alert(j.error || 'Failed to load history'); return; }
      histPkgId = id;
      $('#histTitle').textContent = title;
      const tbody = $('#histTbl tbody');
      tbody.innerHTML = '';
      for (const row of (j.data || [])) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${row.created_at}</td>
          <td>${(+row.lat).toFixed(6)}, ${(+row.lng).toFixed(6)}</td>
          <td>${row.address ? row.address : ''}</td>
          <td>${row.note ? row.note : ''}</td>
        `;
        tbody.appendChild(tr);
      }
      $('#historyBox').style.display = 'block';
    }

    // Add note at current position
    let histPkgId = null; // last history package shown
    window.addNote = async function(id){
      const note = prompt('Enter note (e.g., Passed customs, Handed to courier):');
      if (!note) return;
      const j = await post('addNote', { id, note });
      if (!j.ok) { alert(j.error || 'Failed to add note'); return; }
      await loadList();
      if (histPkgId === id) {
        showHistory(id, (currentData.find(x=>x.id===id)?.tracking_number)||'');
      }
    }

    // History header Add note button
    document.getElementById('histAddNoteBtn')?.addEventListener('click', ()=>{ if (histPkgId) addNote(histPkgId); });

    // Route layers for start/destination visualization
    let routeStart = null, routeDest = null, routeLine = null;
    // Additions for focus view: traveled path, stop dots, and current marker highlight
    let routeTraveled = null;
    let routeStopDots = [];
    let prevFocusedId = null;
    let prevFocusedIcon = null;
    const currentHighlightIcon = L.divIcon({ className:'', html:'<div style="width:26px;height:26px;border-radius:50%;background:#ffcd00;border:2px solid #b58900;box-shadow:0 0 0 3px #fff, 0 0 12px rgba(255,205,0,0.8)"></div>', iconSize:[26,26], iconAnchor:[13,13]});
    function clearRoute(){
      if(routeStart){ map.removeLayer(routeStart); routeStart = null; }
      if(routeDest){ map.removeLayer(routeDest); routeDest = null; }
      if(routeLine){ map.removeLayer(routeLine); routeLine = null; }
      if(routeTraveled){ map.removeLayer(routeTraveled); routeTraveled = null; }
      if(routeStopDots && routeStopDots.length){ routeStopDots.forEach(d=>{ try{ map.removeLayer(d); }catch{} }); routeStopDots = []; }
      // Restore previously highlighted marker icon
      if (prevFocusedId !== null && markers.has(prevFocusedId) && prevFocusedIcon) {
        try { markers.get(prevFocusedId).setIcon(prevFocusedIcon); } catch{}
      }
      prevFocusedId = null; prevFocusedIcon = null;
    }

    async function geocode(q){
      if(!q) return null;
      try{
        const r = await fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(q), { headers: { 'Accept':'application/json' }});
        const arr = await r.json();
        if(!arr.length) return null;
        return [parseFloat(arr[0].lat), parseFloat(arr[0].lon)];
      }catch{ return null; }
    }

    // Draw full focus route similar to client: start/dest markers, dashed full route, solid traveled path through history, and highlight current marker
    async function drawFocusRoute(pkgRow, historyRows){
      clearRoute();
      const startQ = (pkgRow.arriving || '').trim();
      const destQ  = (pkgRow.destination || '').trim();
      const startLL = await geocode(startQ);
      const destLL  = await geocode(destQ);

      const stopsChrono = Array.isArray(historyRows) ? historyRows.slice().reverse().map(r=>[parseFloat(r.lat), parseFloat(r.lng)]) : [];
      const curLL = (pkgRow.last_lat!==null && pkgRow.last_lng!==null) ? [pkgRow.last_lat, pkgRow.last_lng] : null;
      if (stopsChrono.length === 0 && curLL) stopsChrono.push(curLL);

      const routeFull = [];
      if (startLL) routeFull.push(startLL);
      for (const pt of stopsChrono) routeFull.push(pt);
      if (destLL) routeFull.push(destLL);

      // Start/Dest markers
      const startIcon = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#16a34a;border:2px solid #0f7a37;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});
      const destIcon  = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#ef4444;border:2px solid #b91c1c;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});
      if (startLL) routeStart = L.marker(startLL, {icon:startIcon}).addTo(map).bindTooltip('Start');
      if (destLL)  routeDest  = L.marker(destLL,  {icon:destIcon}).addTo(map).bindTooltip('Destination');

      // Dashed full route
      if (routeFull.length >= 2) {
        routeLine = L.polyline(routeFull, { color:'#111', weight:3, opacity:0.5, dashArray:'6 6' }).addTo(map);
      }
      // Solid traveled path from start through stops
      const traveledPts = [];
      if (startLL) traveledPts.push(startLL);
      for (const s of stopsChrono) traveledPts.push(s);
      if (traveledPts.length >= 2) {
        routeTraveled = L.polyline(traveledPts, { color:'#D40511', weight:4, opacity:0.85 }).addTo(map);
      }
      // Stop dots
      for (const s of stopsChrono) {
        const dot = L.circleMarker(s, { radius:4, color:'#D40511', weight:1, fillColor:'#FFCC00', fillOpacity:0.9 }).addTo(map);
        routeStopDots.push(dot);
      }

      // Highlight current package marker icon differently
      if (pkgRow.id && markers.has(pkgRow.id)) {
        try {
          const m = markers.get(pkgRow.id);
          prevFocusedId = pkgRow.id;
          prevFocusedIcon = m.options.icon || new L.Icon.Default();
          m.setIcon(currentHighlightIcon);
          // Keep popup behavior
        } catch{}
      }

      // Fit bounds
      const layers = [];
      for (const p of routeFull) layers.push(p);
      if (layers.length){ if (autoZoomEnabled) { map.fitBounds(layers, { padding:[30,30] }); } }
    }

    async function drawRouteFor(pkg){
      clearRoute();
      const startQ = pkg.arriving || '';
      const destQ  = pkg.destination || '';
      const startLL = await geocode(startQ);
      const destLL  = await geocode(destQ);
      const layers = [];
      const startIcon = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#16a34a;border:2px solid #0f7a37;box-shadow:0 0 0 2px #fff"></div>' , iconSize:[22,22], iconAnchor:[11,11]});
      const destIcon  = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#ef4444;border:2px solid #b91c1c;box-shadow:0 0 0 2px #fff"></div>' , iconSize:[22,22], iconAnchor:[11,11]});

      if(startLL){ routeStart = L.marker(startLL, {icon:startIcon}).addTo(map); layers.push(startLL); }
      if(destLL){ routeDest  = L.marker(destLL,  {icon:destIcon}).addTo(map); layers.push(destLL); }
      if(startLL && destLL){
        routeLine = L.polyline([startLL, destLL], { color:'#111', weight:3, opacity:0.6, dashArray:'6 6' }).addTo(map);
      }
      // Fit bounds to include current position too if present
      const cur = currentData.find(x=>x.id===pkg.id);
      if(cur && cur.last_lat!==null && cur.last_lng!==null){ layers.push([cur.last_lat, cur.last_lng]); }
      if(layers.length){ if (autoZoomEnabled) { map.fitBounds(layers, { padding:[30,30] }); } }
    }

    // Edit panel logic (modal)
    let editId = null;

    window.openEdit = async function(id){
      const j = await post('getPackage', { id });
      if(!j.ok){ alert(j.error || 'Failed to load'); return; }
      const d = j.data;
      editId = d.id;

      // Query modal elements locally and guard against nulls
      const getEl = (i)=>document.getElementById(i);
      const mModal = getEl('editModal');
      const mTracking = getEl('editTracking');
      const mTitle = getEl('editTitle');
      const mArr = getEl('editArriving');
      const mDest = getEl('editDestination');
      const mDelivery = getEl('editDelivery');
      const mStatus = getEl('editStatus');
      const mDesc = getEl('editDescription');
      const mThumb = getEl('editThumb');

      if (!mModal) {
        console.warn('Edit modal root element not found.');
        return;
      }

      if (mTracking) mTracking.textContent = d.tracking_number;
      if (mTitle) mTitle.value = d.title || '';
      if (mArr) mArr.value = d.arriving || '';
      if (mDest) mDest.value = d.destination || '';
      if (mDelivery) mDelivery.value = d.delivery_option || '';
      if (mStatus) mStatus.value = (d.status || '').toLowerCase();
      if (mDesc) mDesc.value = d.description || '';
      if (mThumb) {
        if(d.image_path){ mThumb.src = '../' + d.image_path; mThumb.style.display = 'block'; }
        else { mThumb.style.display = 'none'; }
      }

      mModal.classList.add('show');
      drawRouteFor(d);
      initEditPickPreview(d); // add draggable start/dest markers and dashed line
    }

    function closeEdit(){ 
      const editModal = document.getElementById('editModal');
      if (editModal) editModal.classList.remove('show'); 
      clearRoute(); 
      clearPickArtifacts(); 
      applyPickHighlight(false); 
      editId=null; 
      const imgInp = document.getElementById('editImage'); if (imgInp) imgInp.value='';
    }

    // Event delegation so handlers work even if modal is added later
    document.addEventListener('click', async (e)=>{
      const target = e.target;
      if (target.closest && target.closest('#editClose')) {
        e.preventDefault();
        closeEdit();
        return;
      }
      // close when clicking on the overlay outside dialog
      if (target === document.getElementById('editModal')) {
        closeEdit();
        return;
      }
      if (target.closest && target.closest('#editSave')) {
        e.preventDefault();
        if(!editId) { closeEdit(); return; }
        const fd = new FormData();
        fd.append('action','updatePackage');
        fd.append('id', String(editId));
        fd.append('title', ((document.getElementById('editTitle')?.value) || '').trim());
        fd.append('arriving', ((document.getElementById('editArriving')?.value) || '').trim());
        fd.append('destination', ((document.getElementById('editDestination')?.value) || '').trim());
        fd.append('delivery_option', ((document.getElementById('editDelivery')?.value) || '').trim());
        fd.append('status', ((document.getElementById('editStatus')?.value) || '').trim());
        fd.append('description', ((document.getElementById('editDescription')?.value) || '').trim());
        const editImageEl = document.getElementById('editImage');
        if (editImageEl && editImageEl.files && editImageEl.files[0]) {
          fd.append('newImage', editImageEl.files[0]);
        }
        const r = await fetch('', { method:'POST', body: fd });
        const j = await r.json();
        if(!j.ok){ alert(j.error || 'Save failed'); return; }
        await loadList();
        closeEdit();
      }
    });

    window.addEventListener('keydown', (e)=>{ if(e.key==='Escape' && document.getElementById('editModal')?.classList.contains('show')) closeEdit(); });

    // Search / refresh
    $('#search').addEventListener('input', ()=>{
      // debounce would be nice; keep simple
      loadList();
    });
    $('#refreshBtn').addEventListener('click', loadList);

    // Add package (with optional address geocoding and image upload)
    $('#addBtn').addEventListener('click', async (e)=>{
      e?.preventDefault?.();
      const tracking = $('#newTracking').value.trim();
      const title    = $('#newTitle').value.trim();
      const addr     = $('#newAddress').value.trim();
      const arriving = $('#newArriving').value.trim();
      const destination = $('#newDestination').value.trim();
      const deliveryOption = $('#newDeliveryOption')?.value.trim() || '';
      const description = $('#newDescription').value.trim();
      const imageInput = $('#newImage');
      const imageFile = imageInput.files[0];
      let lat = null, lng = null, address = '';

      if (!tracking) { alert('Tracking number is required'); return; }

      if (addr) {
        try {
          const url = 'https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(addr);
          const r = await fetch(url, { headers:{'Accept':'application/json'} });
          const arr = await r.json();
          if (arr.length) {
            lat = parseFloat(arr[0].lat);
            lng = parseFloat(arr[0].lon);
            address = addr;
          }
        } catch(e){ /* ignore geocode error */ }
      }

      const formData = new FormData();
      formData.append('action', 'addPackage');
      formData.append('tracking', tracking);
      formData.append('title', title);
      if (lat != null && lng != null) {
        formData.append('lat', String(lat));
        formData.append('lng', String(lng));
      }
      if (address) formData.append('address', address);
      formData.append('arriving', arriving);
      formData.append('destination', destination);
      formData.append('delivery_option', deliveryOption);
      formData.append('description', description);
      if (imageFile) {
        formData.append('newImage', imageFile);
      }

      try{
        const resp = await fetch('', { method:'POST', body: formData });
        const j = await resp.json();
        if (!j.ok) { alert(j.error || 'Create failed'); return; }
      }catch(err){
        alert('Network or server error while creating');
        return;
      }
      // reset form
      $('#newTracking').value = '';
      $('#newTitle').value = '';
      $('#newAddress').value = '';
      $('#newArriving').value = '';
      $('#newDestination').value = '';
      if ($('#newDeliveryOption')) $('#newDeliveryOption').value = '';
      $('#newDescription').value = '';
      imageInput.value = '';
      await loadList();
    });

    // Change password
    document.getElementById('changePasswordBtn')?.addEventListener('click', async ()=>{
      const fd = new FormData();
      fd.append('action','changePassword');
      fd.append('currentPassword', document.getElementById('currentPassword').value);
      fd.append('newPassword', document.getElementById('newPassword').value);
      fd.append('confirmPassword', document.getElementById('confirmPassword').value);
      const r = await fetch('', {method:'POST', body:fd});
      const j = await r.json();
      if (j.ok) alert('Password changed'); else alert(j.error||'Error');
    });

    // Crisp settings
    async function loadCrisp(){
      const j = await post('getCrispSettings');
      if (!j.ok) return;
      document.getElementById('crispEnabled').checked = j.data.enabled === '1';
      document.getElementById('crispWebsiteId').value = j.data.website_id || '';
      document.getElementById('crispSchedEnabled').checked = j.data.sched_enabled === '1';
      const days = (j.data.days||'1,2,3,4,5').split(',').map(x=>parseInt(x,10));
      document.querySelectorAll('.crispDay').forEach(cb=>{ cb.checked = days.includes(parseInt(cb.value,10)); });
      document.getElementById('crispStart').value = j.data.start || '09:00';
      document.getElementById('crispEnd').value = j.data.end || '18:00';
    }
    loadCrisp();

    document.getElementById('crispSaveBtn')?.addEventListener('click', async ()=>{
      const days = Array.from(document.querySelectorAll('.crispDay:checked')).map(cb=>cb.value).join(',');
      const payload = {
        enabled: document.getElementById('crispEnabled').checked ? '1' : '0',
        website_id: document.getElementById('crispWebsiteId').value.trim(),
        sched_enabled: document.getElementById('crispSchedEnabled').checked ? '1' : '0',
        days,
        start: document.getElementById('crispStart').value || '09:00',
        end: document.getElementById('crispEnd').value || '18:00',
      };
      const j = await post('saveCrispSettings', payload);
      if (j.ok) alert('Saved'); else alert(j.error||'Save failed');
    });
  </script>
<?php endif; ?>
</main>

<!-- Edit Package Modal (moved) -->
<div id="editModal" class="modal" aria-hidden="true">
  <div class="modal-dialog card">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <h3 style="display:flex; align-items:center; gap:8px; margin:0;"><i class="ri-edit-2-line"></i> Edit package <span class="badge" id="editTracking" style="margin-left:8px;"></span></h3>
      <button id="editClose" class="btn-ghost"><i class="ri-close-line"></i> Close</button>
    </div>
    <div class="grid" style="margin-top:12px;">
      <div class="row">
        <div class="input-wrap" style="flex:1 1 260px;"><i class="ri-edit-line"></i><input type="text" id="editTitle" placeholder="Title" class="plain" style="width:100%"></div>
        <div class="input-wrap" style="flex:1 1 220px;"><i class="ri-truck-line"></i><input type="text" id="editDelivery" placeholder="Delivery option" class="plain" style="width:100%"></div>
      </div>
      <div class="row">
        <div class="input-wrap" style="flex:1 1 auto;"><i class="ri-send-plane-line"></i><input type="text" id="editArriving" placeholder="Arriving (start)" class="plain" style="width:100%"></div>
        <button type="button" id="editArrPickBtn" class="btn-small"><i class="ri-focus-2-line"></i> Pick start</button>
      </div>
      <div class="row">
        <div class="input-wrap" style="flex:1 1 auto;"><i class="ri-flag-line"></i><input type="text" id="editDestination" placeholder="Destination (end)" class="plain" style="width:100%"></div>
        <button type="button" id="editDestPickBtn" class="btn-small"><i class="ri-focus-2-line"></i> Pick destination</button>
      </div>
      <div class="row">
        <div class="input-wrap"><i class="ri-information-line"></i>
          <select id="editStatus" class="plain">
            <option value="">— status —</option>
            <option value="created">Created</option>
            <option value="in_transit">In transit</option>
            <option value="delivered">Delivered</option>
          </select>
        </div>
      </div>
      <div class="row" style="align-items:flex-start;">
        <div class="textarea-wrap" style="flex:1 1 320px;"><textarea id="editDescription" placeholder="Description"></textarea></div>
        <div style="display:flex; flex-direction:column; gap:8px;">
          <img id="editThumb" alt="Preview" style="max-width:160px; display:none; border-radius:10px; border:1px solid var(--border); background:#fff;">
          <input type="file" id="editImage" accept="image/*">
        </div>
      </div>
      <div class="row">
        <button id="editSave"><i class="ri-save-3-line"></i> Save</button>
      </div>
    </div>
  </div>
</div>
<!-- /Edit Package Modal (moved) -->

<?php require_once __DIR__ . '/../footer.php'; ?>
<script>
    // Update Footer
    document.getElementById('updateBtnFooter')?.addEventListener('click', async ()=>{
      if (!confirm('Are you sure you want to update from the repository? This may overwrite local changes.')) return;
      const j = await post('update');
      if (j.ok) {
        alert('Updated successfully!');
        location.reload();
      } else {
        alert('Update failed: ' + j.error);
      }
    });
</script>
</body>
</html>
