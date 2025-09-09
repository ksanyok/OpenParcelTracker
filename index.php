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
            $current = VERSION;
            return [
                'latest' => $latest,
                'current' => $current,
                'update_available' => version_compare($latest, $current, '>')
            ];
        }
    }
    return null;
}

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

$version_info = checkVersion();
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
  body{
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    margin: 0;
    padding: 0;
    color: #111;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    position: relative;
    overflow-x: hidden;
  }
  body::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(45deg, rgba(212, 5, 17, 0.05) 0%, rgba(255, 204, 0, 0.05) 100%);
    z-index: -1;
  }
  .floating {
    position: absolute;
    border-radius: 50%;
    opacity: 0.1;
    animation: float 8s ease-in-out infinite;
    z-index: -1;
  }
  .floating:nth-child(1) {
    width: 60px;
    height: 60px;
    background: #D40511;
    top: 10%;
    left: 10%;
    animation-delay: 0s;
  }
  .floating:nth-child(2) {
    width: 40px;
    height: 40px;
    background: #FFCC00;
    top: 20%;
    right: 15%;
    animation-delay: 2s;
  }
  .floating:nth-child(3) {
    width: 80px;
    height: 80px;
    background: #D40511;
    bottom: 20%;
    left: 20%;
    animation-delay: 4s;
  }
  .floating:nth-child(4) {
    width: 50px;
    height: 50px;
    background: #FFCC00;
    bottom: 10%;
    right: 10%;
    animation-delay: 6s;
  }
  @keyframes float {
    0%, 100% { transform: translateY(0px) rotate(0deg); }
    50% { transform: translateY(-30px) rotate(180deg); }
  }
  header{
    padding: 20px;
    background: linear-gradient(90deg, #D40511 0%, #FFCC00 100%);
    color: #fff;
    text-align: center;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
  }
  main{
    padding: 20px;
    max-width: 1100px;
    margin: 0 auto;
    flex: 1;
    position: relative;
    z-index: 1;
  }
  footer{
    margin-top: auto;
    text-align: center;
    padding: 15px;
    background: linear-gradient(90deg, #D40511 0%, #FFCC00 100%);
    color: #fff;
    box-shadow: 0 -4px 6px rgba(0,0,0,0.1);
  }
  .card{
    background: #fff;
    border: none;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 8px 32px rgba(212, 5, 17, 0.1);
    margin-bottom: 20px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }
  .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 40px rgba(212, 5, 17, 0.2);
  }
  .row{
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
  }
  .field{
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
  }
  input[type=text]{
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    min-width: 260px;
    font-size: 16px;
    transition: border-color 0.3s ease;
  }
  input[type=text]:focus {
    border-color: #D40511;
    outline: none;
  }
  button{
    padding: 12px 20px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(90deg, #D40511 0%, #FFCC00 100%);
    color: #fff;
    cursor: pointer;
    font-weight: bold;
    transition: background 0.3s ease, transform 0.2s ease;
  }
  button:hover{
    background: linear-gradient(90deg, #b0040f 0%, #e6b800 100%);
    transform: scale(1.05);
  }
  #map{
    height: 450px;
    border-radius: 15px;
    border: 2px solid #e5e7eb;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  }
  table{
    width: 100%;
    border-collapse: collapse;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  }
  th, td{
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
  }
  th {
    background: linear-gradient(90deg, #D40511 0%, #FFCC00 100%);
    color: #fff;
  }
  .muted{
    color: #64748b;
  }
  .badge{
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    background: linear-gradient(90deg, #D40511 0%, #FFCC00 100%);
    color: #fff;
    font-size: 14px;
    font-weight: bold;
  }
  .hint{
    font-size: 14px;
    color: #64748b;
  }
  .update-notice{
    background: linear-gradient(90deg, #fff3cd 0%, #ffeaa7 100%);
    color: #856404;
    padding: 15px;
    text-align: center;
    border: 2px solid #ffeaa7;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  }
  @media (max-width: 768px) {
    main {
      padding: 10px;
    }
    .row {
      flex-direction: column;
    }
    .field {
      flex-direction: column;
      align-items: stretch;
    }
    input[type=text] {
      min-width: auto;
      width: 100%;
    }
    #map {
      height: 300px;
    }
    .card {
      padding: 15px;
    }
  }
</style>
</head>
<body>
<div class="floating"></div>
<div class="floating"></div>
<div class="floating"></div>
<div class="floating"></div>
<header>
  <h1>Package Tracker</h1>
</header>
<?php if ($version_info && $version_info['update_available']): ?>
<div class="update-notice">
  Update available to version <strong><?php echo h($version_info['latest']); ?></strong> (current: <?php echo h($version_info['current']); ?>). <a href="admin/index.php"><button>Update</button></a>
</div>
<?php endif; ?>
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
<p><strong>Destination:</strong> <?=h((string)$pkg['destination'])?></p>
<p><strong>Delivery option:</strong> <?=h((string)$pkg['delivery_option'])?></p>
<p><strong>Images and Description:</strong> <?=h((string)$pkg['description'])?></p>
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
