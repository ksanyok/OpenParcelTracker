<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/db.php';

/**
 * Public page:
 * - Search by tracking number (?tracking=XXXX)
 * - Show map with current package position
 * - Show movement history (locations)
 */

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$pdo = pdo();

// Load by tracking number if provided
$tracking = isset($_GET['tracking']) ? trim((string)$_GET['tracking']) : '';
$pkg = null;
$history = [];
if ($tracking !== '') {
    $stm = $pdo->prepare("SELECT * FROM packages WHERE tracking_number = ?");
    $stm->execute([$tracking]);
    $pkg = $stm->fetch();

    if ($pkg) {
        $stmH = $pdo->prepare("SELECT id, lat, lng, address, note, created_at 
                               FROM locations WHERE package_id = ? 
                               ORDER BY created_at DESC");
        $stmH->execute([$pkg['id']]);
        $history = $stmH->fetchAll();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Package Tracker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
/>
<style>
  body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; padding:0; color:#111;}
  header{padding:16px 20px; background:#0f172a; color:#fff;}
  main{padding:20px; max-width:1100px; margin:0 auto;}
  .card{background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,.04);}
  .row{display:flex; gap:16px; flex-wrap:wrap;}
  .field{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
  input[type=text]{padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; min-width:260px;}
  button{padding:10px 14px; border:0; border-radius:10px; background:#2563eb; color:#fff; cursor:pointer;}
  button:hover{background:#1d4ed8;}
  #map{height:420px; border-radius:12px; border:1px solid #e5e7eb;}
  table{width:100%; border-collapse:collapse;}
  th, td{padding:8px 10px; border-bottom:1px solid #e5e7eb; text-align:left;}
  .muted{color:#64748b;}
  .badge{display:inline-block; padding:2px 8px; border-radius:999px; background:#f1f5f9; color:#0f172a; font-size:12px;}
  .hint{font-size:12px; color:#64748b;}
</style>
</head>
<body>
<header>
  <h1>Package Tracker</h1>
</header>
<main>
  <div class="card" style="margin-bottom:16px;">
    <form method="get" class="field">
      <label for="tracking"><strong>Find package by Tracking #</strong></label>
      <input type="text" id="tracking" name="tracking" placeholder="e.g. PKG-12345" value="<?=h($tracking)?>">
      <button type="submit">Search</button>
      <span class="hint">Enter tracking number to view the package on the map and its movement history.</span>
    </form>
  </div>

  <?php if ($tracking !== '' && !$pkg): ?>
    <div class="card">
      <p>No package found for tracking number: <strong><?=h($tracking)?></strong></p>
    </div>
  <?php endif; ?>

  <?php if ($pkg): ?>
  <div class="card" style="margin-bottom:16px;">
    <div class="row">
      <div style="flex:1 1 320px;">
        <p><strong>Tracking #:</strong> <span class="badge"><?=h($pkg['tracking_number'])?></span></p>
<p><strong>Title:</strong> <?=h((string)$pkg['title'])?></p>
<p><strong>Arriving:</strong> <?=h((string)$pkg['arriving'])?></p>
<p><strong>Your order will be send to:</strong> <?=h((string)$pkg['destination'])?></p>
<p><strong>You delivery option:</strong> <?=h((string)$pkg['delivery_option'])?></p>
<p><strong>Photos and Description:</strong> <?=h((string)$pkg['description'])?></p>
<p class="muted">Updated: <?=h((string)$pkg['updated_at'])?></p>

        <?php if ($pkg['last_address']): ?>
          <p><strong>Current address:</strong> <?=h((string)$pkg['last_address'])?></p>
        <?php endif; ?>
        <?php if ($pkg['last_lat'] !== null && $pkg['last_lng'] !== null): ?>
          <p><strong>Coords:</strong> <?=h((string)$pkg['last_lat'])?>, <?=h((string)$pkg['last_lng'])?></p>
        <?php else: ?>
          <p class="muted">No coordinates yet.</p>
        <?php endif; ?>

        <?php if (!empty($pkg['image_path'])): ?>
          <img src="<?=h((string)$pkg['image_path'])?>" alt="Image" style="max-width:300px; margin-top:10px;">
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div id="map"></div>

  <div class="card" style="margin-top:16px;">
    <h3>Movement history</h3>
    <?php if (!$history): ?>
      <p class="muted">No history yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Coordinates</th>
            <th>Address</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $row): ?>
          <tr>
            <td><?=h((string)$row['created_at'])?></td>
            <td><?=h((string)$row['lat'])?>, <?=h((string)$row['lng'])?></td>
            <td><?=h((string)($row['address'] ?? ''))?></td>
            <td><?=h((string)($row['note'] ?? ''))?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin="">
  </script>
  <script>
    (function(){
      const pkg = <?=json_encode($pkg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
      const hasPoint = pkg.last_lat !== null && pkg.last_lng !== null;

      const map = L.map('map');
      const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      if (hasPoint) {
        const m = L.marker([pkg.last_lat, pkg.last_lng]).addTo(map);
        const addr = pkg.last_address ? `<br><small>${pkg.last_address}</small>` : '';
        m.bindPopup(`<b>${pkg.tracking_number}</b>${addr}`);
        map.setView([pkg.last_lat, pkg.last_lng], 13);
      } else {
        map.setView([31.0461, 34.8516], 7); // Israel area as neutral default
      }
    })();
  </script>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
