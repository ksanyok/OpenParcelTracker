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
    $url = 'https://api.github.com/repos/ksanyok/OpenParcelTracker/releases/latest';
    $context = stream_context_create([
        'http' => [
            'header' => 'User-Agent: PHP',
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    if ($response) {
        $data = json_decode($response, true);
        $latest = $data['tag_name'] ?? null;
        $current = get_version();
        return [
            'latest' => $latest,
            'current' => $current,
            'update_available' => $latest && version_compare($latest, $current, '>')
        ];
    }
    return null;
}

$pdo = pdo();

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

    if ($action === 'update') {
        // Update from repository
        $output = [];
        $returnVar = 0;
        exec('git pull origin main 2>&1', $output, $returnVar);
        if ($returnVar === 0) {
            echo json_encode(['ok' => true, 'message' => 'Updated successfully: ' . implode("\n", $output)]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Update failed: ' . implode("\n", $output)]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
    exit;
}

$logged = is_logged_in();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin • Package Tracker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
/>
<style>
  :root{
    --bg:#f8fafc; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e5e7eb; --primary:#2563eb; --primary-2:#1d4ed8;
  }
  *{box-sizing:border-box}
  body{margin:0; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--text);}
  header{padding:16px 20px; background:#0f172a; color:#fff; display:flex; align-items:center; justify-content:space-between;}
  main{padding:20px; max-width:1200px; margin:0 auto;}
  .card{background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,.04);}
  input, button{font:inherit;}
  input[type=text], input[type=password]{padding:10px 12px; border:1px solid var(--border); border-radius:10px;}
  button{padding:10px 14px; border:0; border-radius:10px; background:var(--primary); color:#fff; cursor:pointer;}
  button:hover{background:var(--primary-2);}
  #map{height:520px; border-radius:12px; border:1px solid var(--border);}
  .grid{display:grid; gap:16px;}
  .grid-2{grid-template-columns: 1fr 1fr;}
  .row{display:flex; gap:12px; align-items:center; flex-wrap:wrap;}
  .muted{color:var(--muted);}
  table{width:100%; border-collapse:collapse; font-size:14px;}
  th, td{padding:8px 10px; border-bottom:1px solid var(--border); text-align:left;}
  .badge{display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:12px;}
  .list{max-height:420px; overflow:auto;}
  .link{color:var(--primary); text-decoration:underline; cursor:pointer;}
  .right{margin-left:auto;}
  .hint{font-size:12px; color:var(--muted);}
</style>
</head>
<body>
<header>
  <strong>Admin • Package Tracker</strong>
  <?php if ($logged): ?>
    <div>
      <button id="updateBtn" title="Update from repository">Update</button>
      <button id="logoutBtn" title="Log out">Log out</button>
    </div>
  <?php endif; ?>
</header>
<?php if ($logged): ?>
  <?php $version_info = checkVersion(); ?>
  <?php if ($version_info && $version_info['update_available']): ?>
    <div style="background: #fff3cd; color: #856404; padding: 10px; text-align: center; border: 1px solid #ffeaa7;">
      New version available: <strong><?php echo h($version_info['latest']); ?></strong> (current: <?php echo h($version_info['current']); ?>). <button id="updateNowBtn">Update Now</button>
    </div>
  <?php endif; ?>
<?php endif; ?>
<main>

<?php if (!$logged): ?>
  <div class="card" style="max-width:420px; margin:40px auto;">
    <h2>Sign in</h2>
    <form id="loginForm" class="grid" onsubmit="return false;">
      <label>Username<br><input type="text" id="u" value="admin" autocomplete="username"></label>
      <label>Password<br><input type="password" id="p" value="admin123" autocomplete="current-password"></label>
      <button id="loginBtn">Sign in</button>
      <p class="hint">Default credentials are <code>admin / admin123</code> (auto-created on first run). Change ASAP.</p>
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
      <h3>Map</h3>
      <div id="map"></div>
      <p class="hint" style="margin-top:8px;">Tip: drag a marker to move a package. Click a marker to set by address or view mini-info.</p>
    </div>
    <div class="card">
      <h3>Packages</h3>
      <div class="row">
        <input type="text" id="search" placeholder="Filter by tracking or title…" style="flex:1 1 auto;">
        <button id="refreshBtn">Refresh</button>
      </div>
      <div class="list" style="margin-top:12px;">
        <table id="tbl">
          <thead>
            <tr>
              <th>Tracking #</th>
              <th>Title</th>
              <th>Coords</th>
              <th>Updated</th>
              <th></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <hr style="margin:16px 0; border:0; border-top:1px solid var(--border);">

      <h3>New package</h3>
      <form id="addForm" class="grid" enctype="multipart/form-data">
        <div class="row">
          <input type="text" id="newTracking" placeholder="Tracking number *" required>
          <input type="text" id="newTitle" placeholder="Title (optional)">
        </div>
        <div class="row">
          <input type="text" id="newAddress" placeholder="Initial address (optional – will be geocoded)">
          <input type="text" id="newArriving" placeholder="Arriving location *" required>
        </div>
        <div class="row">
          <input type="text" id="newDestination" placeholder="Your order will be send to *" required>
          <input type="text" id="newDeliveryOption" placeholder="Your delivery option *" required>
        </div>
        <div class="row">
          <textarea id="newDescription" placeholder="Photos and Description *" rows="3" required></textarea>
          <input type="file" id="newImage" accept="image/*">
          <button id="addBtn">Create</button>
        </div>
        <p class="hint">If address is provided, it will be geocoded via Nominatim and used as initial coordinates.</p>
      </form>

      <div id="historyBox" style="margin-top:16px; display:none;">
        <h3>History: <span id="histTitle"></span></h3>
        <div class="list">
          <table id="histTbl">
            <thead><tr><th>Date</th><th>Coords</th><th>Address</th><th>Note</th></tr></thead>
            <tbody></tbody>
          </table>
          <?php if (!empty($pkg['image_path'])): ?>
            <img src="<?=h((string)$pkg['image_path'])?>" alt="Image" style="max-width:300px; margin-top:10px;">
          <?php endif; ?>
        </div>
      </div>
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
        alert('Update successful: ' + j.message);
        location.reload();
      } else {
        alert('Update failed: ' + j.error);
      }
    });

    // Update Now
    document.getElementById('updateNowBtn')?.addEventListener('click', async ()=>{
      if (!confirm('Update to the latest version?')) return;
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
          <td><span class="link" onclick="focusPkg(${r.id})">Focus</span></td>
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
      if (!arriving || !destination || !deliveryOption || !description) {
        alert('Please fill in all required fields.');
        return;
      }

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

    // Initial
    loadList();
  </script>
<?php endif; ?>
</main>
<?php require_once __DIR__ . '/../footer.php'; ?>
</body>
</html>
