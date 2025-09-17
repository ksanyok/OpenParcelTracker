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
        $lat      = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng      = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
        $address  = trim((string)($_POST['address'] ?? ''));
        $arriving = trim((string)($_POST['arriving'] ?? ''));
        $destination = trim((string)($_POST['destination'] ?? ''));
        $deliveryOption = trim((string)($_POST['delivery_option'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $imagePath = ''; // Placeholder for image path handling

        // Handle Image Upload
        if (isset($_FILES['newImage']) && $_FILES['newImage']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../photos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $filename = basename($_FILES['newImage']['name']);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $newFilename = $tracking . '.' . $extension;
            $targetPath = $uploadDir . $newFilename;

            if (move_uploaded_file($_FILES['newImage']['tmp_name'], $targetPath)) {
                $imagePath = 'photos/' . $newFilename;
            } else {
                echo json_encode(['ok'=>false,'error'=>'Failed to upload image']);
                exit;
            }
        }

        if ($tracking === '') {
            echo json_encode(['ok'=>false,'error'=>'Tracking number required']); exit;
        }

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $stm = $pdo->prepare("INSERT INTO packages (tracking_number, title, last_lat, last_lng, last_address, status, image_path, arriving, destination, delivery_option, description, created_at, updated_at)
                                  VALUES (?,?,?,?,?, 'active', ?, ?, ?, ?, ?, ?, ?)");
            $stm->execute([$tracking, $title, $lat, $lng, $address ?: null, $imagePath, $arriving, $destination, $deliveryOption, $description, $now, $now]);

            $pid = (int)$pdo->lastInsertId();

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

    // New: get single package
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

    // New: update package details (with optional image)
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
        if (isset($_FILES['newImage']) && isset($_FILES['newImage']['tmp_name']) && $_FILES['newImage']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../photos/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
            $filename = basename($_FILES['newImage']['name']);
            $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg';
            $newFilename = $cur['tracking_number'] . '.' . $extension;
            $targetPath = $uploadDir . $newFilename;
            if (move_uploaded_file($_FILES['newImage']['tmp_name'], $targetPath)) {
                $imagePath = 'photos/' . $newFilename;
            } else {
                echo json_encode(['ok'=>false,'error'=>'Failed to upload image']);
                exit;
            }
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

        $pdo->beginTransaction();
        try {
            // Delete locations first due to foreign key
            $stm = $pdo->prepare("DELETE FROM locations WHERE package_id = ?");
            $stm->execute([$id]);

            // Delete package
            $stm = $pdo->prepare("DELETE FROM packages WHERE id = ?");
            $stm->execute([$id]);

            $pdo->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'Delete failed']);
        }
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

  @media (max-width: 960px){ .grid-2{grid-template-columns: 1fr;} }

  /* Edit panel */
  #editPanel{ position:fixed; right:16px; bottom:96px; width:360px; max-width:92vw; display:none; }
  #editPanel .thumb{ width:100%; max-height:180px; object-fit:cover; border-radius:10px; border:1px solid var(--border); }
  .tag{ display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; border:1px dashed var(--border); background:#fff; font-size:12px; }
</style>
</head>
<body>
<div class="bg-animated" aria-hidden="true"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>
<header>
  <strong style="display:flex; align-items:center; gap:8px;"><i class="ri-settings-3-line"></i> Admin • Package Tracker</strong>
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
      <form id="addForm" class="grid" enctype="multipart/form-data">
        <div class="row">
          <div class="input-wrap"><i class="ri-hashtag"></i><input type="text" id="newTracking" placeholder="Tracking number *" class="plain" required></div>
          <div class="input-wrap"><i class="ri-edit-line"></i><input type="text" id="newTitle" placeholder="Title (optional)" class="plain"></div>
        </div>
        <div class="row">
          <div class="input-wrap" style="flex:1 1 auto;"><i class="ri-map-pin-line"></i><input type="text" id="newAddress" placeholder="Initial address (optional – will be geocoded)" class="plain" style="width:100%;"></div>
          <div class="input-wrap"><i class="ri-send-plane-line"></i><input type="text" id="newArriving" placeholder="Arriving location" class="plain"></div>
        </div>
        <div class="row">
          <div class="input-wrap"><i class="ri-flag-line"></i><input type="text" id="newDestination" placeholder="Destination" class="plain"></div>
          <div class="input-wrap"><i class="ri-truck-line"></i><input type="text" id="newDeliveryOption" placeholder="Delivery option" class="plain"></div>
        </div>
        <div class="row" style="align-items:flex-start;">
          <div class="textarea-wrap" style="flex:1 1 320px;"><textarea id="newDescription" placeholder="Images and Description"></textarea></div>
          <div style="display:flex; align-items:center; gap:10px;">
            <input type="file" id="newImage" accept="image/*">
            <button id="addBtn"><i class="ri-add-circle-line"></i> Create</button>
          </div>
        </div>
        <p class="hint">If address is provided, it will be geocoded via Nominatim and used as initial coordinates.</p>
      </form>

      <div id="historyBox" style="margin-top:16px; display:none;">
        <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-time-line"></i> History: <span id="histTitle"></span></h3>
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
    </div>
  </div>

  <!-- Edit panel -->
  <div id="editPanel" class="card">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <strong style="display:flex; align-items:center; gap:8px;"><i class="ri-edit-2-line"></i> Edit package</strong>
      <button id="editClose" style="background:#eee; color:#222; box-shadow:none;"><i class="ri-close-line"></i> Close</button>
    </div>
    <div id="editBody" class="grid" style="margin-top:10px;">
      <div class="tag"><i class="ri-hashtag"></i><span id="editTracking"></span></div>
      <label>Title
        <div class="input-wrap"><i class="ri-edit-line"></i><input type="text" id="editTitle" class="plain"></div>
      </label>
      <label>Start (from)
        <div class="input-wrap"><i class="ri-flag-2-line"></i><input type="text" id="editArriving" placeholder="City / address" class="plain"></div>
      </label>
      <label>Destination
        <div class="input-wrap"><i class="ri-map-pin-2-line"></i><input type="text" id="editDestination" placeholder="City / address" class="plain"></div>
      </label>
      <label>Delivery option
        <div class="input-wrap"><i class="ri-truck-line"></i><input type="text" id="editDelivery" class="plain"></div>
      </label>
      <label>Status
        <div class="input-wrap"><i class="ri-alert-line"></i><input type="text" id="editStatus" class="plain" placeholder="e.g., active, delivered"></div>
      </label>
      <label>Description
        <div class="textarea-wrap"><textarea id="editDescription" placeholder="Details"></textarea></div>
      </label>
      <div>
        <img id="editThumb" class="thumb" alt="Image" style="display:none;">
        <div class="row" style="margin-top:8px; align-items:center;">
          <input type="file" id="editImage" accept="image/*">
          <button id="editSave"><i class="ri-save-3-line"></i> Save</button>
        </div>
      </div>
      <p class="hint">On the map: start and destination are highlighted with a dashed route.</p>
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

    // Enable adding a new draggable marker by clicking on the map
    let tempMarker = null;
    map.on('click', (e) => {
      // Remove previous temp marker if any
      if (tempMarker) {
        map.removeLayer(tempMarker);
        tempMarker = null;
      }
      // Create a draggable temp marker at click position
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
            const imageFile = document.getElementById('newImage').files[0];
            const formData = new FormData();
            formData.append('action', 'addPackage');
            formData.append('tracking', tracking);
            formData.append('title', title);
            formData.append('lat', pos.lat);
            formData.append('lng', pos.lng);
            formData.append('address', '');
            formData.append('arriving', ''); // Populate as needed
            formData.append('destination', ''); // Populate as needed
            formData.append('delivery_option', ''); // Populate as needed
            formData.append('description', ''); // Populate as needed
            if (imageFile) {
              formData.append('newImage', imageFile);
            }
            const j = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
            if (!j.ok) { alert(j.error || 'Create failed'); return; }
            map.removeLayer(tempMarker);
            tempMarker = null;
            await loadList();
          };
        }
        if (mkCancel) {
          mkCancel.onclick = () => {
            map.removeLayer(tempMarker);
            tempMarker = null;
          };
        }
      });
    });

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
          <td><span class="link" onclick="focusPkg(${r.id})">Focus</span> | <span class="link" onclick="openEdit(${r.id})">Edit</span> | <span class="link" onclick="deletePkg(${r.id})">Delete</span></td>
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
          map.fitBounds(pts, { padding:[30,30] });
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
      const addr = prompt('Enter address:');
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
    window.focusPkg = function(id){
      const r = currentData.find(x=>x.id===id);
      if (!r) return;
      if (r.last_lat !== null && r.last_lng !== null) {
        map.setView([r.last_lat, r.last_lng], 15);
        const m = markers.get(id);
        if (m) m.openPopup();
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

    // Route layers for start/destination visualization
    let routeStart = null, routeDest = null, routeLine = null;
    function clearRoute(){
      if(routeStart){ map.removeLayer(routeStart); routeStart = null; }
      if(routeDest){ map.removeLayer(routeDest); routeDest = null; }
      if(routeLine){ map.removeLayer(routeLine); routeLine = null; }
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
      if(layers.length){ map.fitBounds(layers, { padding:[30,30] }); }
    }

    // Edit panel logic
    const editPanel = $('#editPanel');
    const editClose = $('#editClose');
    const editTitle = $('#editTitle');
    const editArriving = $('#editArriving');
    const editDestination = $('#editDestination');
    const editDelivery = $('#editDelivery');
    const editStatus = $('#editStatus');
    const editDescription = $('#editDescription');
    const editImage = $('#editImage');
    const editThumb = $('#editThumb');
    const editTracking = $('#editTracking');
    let editId = null;

    window.openEdit = async function(id){
      const j = await post('getPackage', { id });
      if(!j.ok){ alert(j.error || 'Failed to load'); return; }
      const d = j.data;
      editId = d.id;
      editTracking.textContent = d.tracking_number;
      editTitle.value = d.title || '';
      editArriving.value = d.arriving || '';
      editDestination.value = d.destination || '';
      editDelivery.value = d.delivery_option || '';
      editStatus.value = d.status || '';
      editDescription.value = d.description || '';
      if(d.image_path){ editThumb.src = '../' + d.image_path; editThumb.style.display = 'block'; } else { editThumb.style.display = 'none'; }
      editPanel.style.display = 'block';
      drawRouteFor(d);
    }

    function closeEdit(){ editPanel.style.display = 'none'; clearRoute(); editId=null; editImage.value=''; }
    editClose.addEventListener('click', closeEdit);

    $('#editSave').addEventListener('click', async ()=>{
      if(!editId) return;
      const fd = new FormData();
      fd.append('action','updatePackage');
      fd.append('id', editId);
      fd.append('title', editTitle.value.trim());
      fd.append('arriving', editArriving.value.trim());
      fd.append('destination', editDestination.value.trim());
      fd.append('delivery_option', editDelivery.value.trim());
      fd.append('status', editStatus.value.trim());
      fd.append('description', editDescription.value.trim());
      if(editImage.files[0]) fd.append('newImage', editImage.files[0]);
      const r = await fetch('', { method:'POST', body: fd });
      const j = await r.json();
      if(!j.ok){ alert(j.error || 'Save failed'); return; }
      await loadList();
      closeEdit();
    });

    // Search / refresh
    $('#search').addEventListener('input', ()=>{
      // debounce would be nice; keep simple
      loadList();
    });
    $('#refreshBtn').addEventListener('click', loadList);

    // Add package (with optional address geocoding and image upload)
    $('#addBtn').addEventListener('click', async ()=>{
      const tracking = $('#newTracking').value.trim();
      const title    = $('#newTitle').value.trim();
      const addr     = $('#newAddress').value.trim();
      const arriving = $('#newArriving').value.trim();
      const destination = $('#newDestination').value.trim();
      const deliveryOption = $('#newDeliveryOption').value.trim();
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
      formData.append('lat', lat);
      formData.append('lng', lng);
      formData.append('address', address);
      formData.append('arriving', arriving);
      formData.append('destination', destination);
      formData.append('delivery_option', deliveryOption);
      formData.append('description', description);
      if (imageFile) {
        formData.append('newImage', imageFile);
      }

      const j = await fetch('', { method:'POST', body: formData }).then(r => r.json());
      if (!j.ok) { alert(j.error || 'Create failed'); return; }
      // reset form
      $('#newTracking').value = '';
      $('#newTitle').value = '';
      $('#newAddress').value = '';
      $('#newArriving').value = '';
      $('#newDestination').value = '';
      $('#newDeliveryOption').value = '';
      $('#newDescription').value = '';
      imageInput.value = '';
      await loadList();
    });

    // Change password
    $('#changePasswordBtn').addEventListener('click', async ()=>{
      const currentPassword = $('#currentPassword').value.trim();
      const newPassword = $('#newPassword').value.trim();
      const confirmPassword = $('#confirmPassword').value.trim();

      if (!currentPassword || !newPassword || !confirmPassword) {
        alert('All fields are required.');
        return;
      }

      if (newPassword !== confirmPassword) {
        alert('New password and confirmation do not match.');
        return;
      }

      const j = await post('changePassword', { currentPassword, newPassword, confirmPassword });
      if (!j.ok) { alert(j.error || 'Change password failed'); return; }
      alert('Password changed successfully.');
      $('#currentPassword').value = '';
      $('#newPassword').value = '';
      $('#confirmPassword').value = '';
    });

    // Initial
    loadList();
  </script>
<?php endif; ?>
</main>
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
