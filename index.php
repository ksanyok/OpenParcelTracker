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
<meta name="theme-color" content="#D40511">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
  :root{ --brand-yellow:#FFCC00; --brand-red:#D40511; --brand-red-dark:#b1040e; --text:#0b0b0b; --muted:#5b6470; --card-bg:rgba(255,255,255,0.55); --card-border:rgba(255,255,255,0.45); --shadow:0 20px 40px rgba(212,5,17,0.08); }
  *{box-sizing:border-box}
  html, body{height:100%}
  body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; padding:0; color:var(--text); min-height: 100vh; display: flex; flex-direction: column; background: #fff; padding-bottom: 96px;}

  /* Lively animated brand background + blobs */
  .bg-animated{ position:fixed; inset:0; overflow:hidden; z-index:0; pointer-events:none; background: radial-gradient(60vw 60vw at -10% -20%, rgba(255,204,0,0.35), rgba(255,204,0,0) 60%), radial-gradient(50vw 50vw at 110% 10%, rgba(212,5,17,0.25), rgba(212,5,17,0) 60%); filter: saturate(115%); }
  .bg-animated::before, .bg-animated::after{ content:""; position:absolute; inset:-20%; background: conic-gradient(from 0deg, rgba(255,204,0,0.20), rgba(212,5,17,0.15), rgba(255,204,0,0.20)); animation: swirl 28s linear infinite; mix-blend-mode: multiply; }
  .bg-animated::after{ animation-duration: 44s; animation-direction: reverse; opacity:.6 }
  .bg-animated .blob{ position:absolute; width:38vmin; height:38vmin; border-radius:50%; filter: blur(20px); opacity:.55; background: radial-gradient(circle at 30% 30%, rgba(255,204,0,0.9), rgba(255,204,0,0.2) 60%); animation: float 18s ease-in-out infinite; }
  .bg-animated .b1{ left:8%; top:12%; }
  .bg-animated .b2{ right:10%; top:20%; background: radial-gradient(circle at 30% 30%, rgba(212,5,17,0.8), rgba(212,5,17,0.15) 60%); animation-duration: 22s; animation-delay: -4s; }
  .bg-animated .b3{ left:20%; bottom:10%; width:46vmin; height:46vmin; background: radial-gradient(circle at 30% 30%, rgba(255,204,0,0.7), rgba(212,5,17,0.18)); animation-duration: 26s; animation-delay: -8s; }
  @keyframes swirl{ to { transform: rotate(1turn); } }
  @keyframes float{ 0%,100%{ transform: translate3d(0,0,0) scale(1); } 50%{ transform: translate3d(0,-6%,0) scale(1.08); } }

  header{ position: relative; z-index:1; padding:18px 20px; background: linear-gradient(90deg, var(--brand-red) 0%, #e50f1b 35%, var(--brand-yellow) 100%); color:#fff; display:flex; align-items:center; justify-content:space-between; gap:12px; }
  header h1{margin:0; font-size:20px; letter-spacing:.3px; display:flex; align-items:center; gap:8px}
  .brand-badge{display:inline-block; padding:4px 10px; border-radius:999px; background:rgba(255,255,255,0.18); border:1px solid rgba(255,255,255,0.25);}  

  main{position: relative; z-index:1; padding:20px; max-width:1100px; margin:0 auto; width:100%;}
  footer{position: relative; z-index:3;}

  .card{background:var(--card-bg); border:1px solid var(--card-border); border-radius:18px; padding:16px; box-shadow:var(--shadow); backdrop-filter: blur(12px) saturate(120%); position:relative;}
  .card::after{ content:""; position:absolute; inset:0; border-radius:18px; background: linear-gradient(180deg, rgba(255,255,255,0.7), rgba(255,255,255,0) 30%); mask: linear-gradient(#000 0 0) top/100% 1px no-repeat, linear-gradient(#000 0 0) bottom/100% 0 no-repeat; pointer-events:none; opacity:.5 }

  .row{display:flex; gap:16px; flex-wrap:wrap; align-items:center}
  .field{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
  input[type=text]{padding:12px 14px; border:1px solid #e3e6ea; border-radius:12px; min-width:260px; outline:none; transition:.2s border-color, .2s box-shadow; background:rgba(255,255,255,0.8);}
  input[type=text]:focus{border-color: var(--brand-red); box-shadow:0 0 0 3px rgba(212,5,17,0.15)}
  button{padding:12px 16px; border:0; border-radius:12px; background: linear-gradient(180deg, var(--brand-red), var(--brand-red-dark)); color:#fff; cursor:pointer; box-shadow: 0 6px 14px rgba(212,5,17,0.25); transition:.2s transform, .2s opacity, .2s box-shadow; display:inline-flex; align-items:center; gap:8px}
  button i{font-size:18px;}
  button:hover{transform: translateY(-1px); box-shadow:0 10px 20px rgba(212,5,17,0.3)}
  button:active{transform: translateY(0)}
  .btn-secondary{background: linear-gradient(180deg, #ffcd00, #f1b800); color:#111; box-shadow:0 6px 14px rgba(255,204,0,0.35)}

  #map{height:420px; border-radius:16px; border:1px solid rgba(255,255,255,0.5); overflow:hidden; box-shadow: var(--shadow);}
  table{width:100%; border-collapse:collapse;}
  th, td{padding:10px 10px; border-bottom:1px solid #eceff3; text-align:left;}
  .muted{color:var(--muted);} 
  .badge{display:inline-block; padding:3px 10px; border-radius:999px; background:linear-gradient(180deg, #fff 0%, #ffe680 100%); border:1px solid #ffd34d; color:#553300; font-size:12px;}
  .hint{font-size:12px; color:var(--muted);} 
  .update-notice{background: linear-gradient(90deg, #fff7cc, #ffeab3); color:#7c5a00; padding: 10px; text-align: center; border: 1px solid #ffd34d; margin: 12px 20px; border-radius:12px; box-shadow: var(--shadow);} 

  @media (max-width: 640px){ header{padding:14px 16px} header h1{font-size:18px} input[type=text]{min-width: 0; width: 100%;} .field{align-items:stretch} button{width:100%} }

  /* Progress bar styles */
  .progress-card{background:var(--card-bg); border:1px solid var(--card-border); border-radius:18px; padding:16px; box-shadow:var(--shadow); backdrop-filter: blur(12px) saturate(120%);} 
  .progress{position:relative; height:14px; border-radius:999px; background:rgba(0,0,0,0.06); overflow:hidden; border:1px solid rgba(0,0,0,0.06)}
  .progress-fill{position:absolute; left:0; top:0; bottom:0; width:0; background:linear-gradient(90deg, var(--brand-red), #ff7a00, var(--brand-yellow)); box-shadow: inset 0 -1px 2px rgba(0,0,0,0.12)}
  .progress-fill::after{content:""; position:absolute; inset:0; background-image:linear-gradient( -45deg, rgba(255,255,255,.25) 25%, rgba(255,255,255,0) 25%, rgba(255,255,255,0) 50%, rgba(255,255,255,.25) 50%, rgba(255,255,255,.25) 75%, rgba(255,255,255,0) 75%, rgba(255,255,255,0) ); background-size:28px 28px; animation: move 1.2s linear infinite; opacity:.4 }
  @keyframes move { to { background-position: 28px 0; } }
  .status-badge{display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; border:1px solid rgba(0,0,0,0.08)}
  .status-created{background:linear-gradient(180deg, #fff, #fff3c4); color:#7c5a00; border-color:#ffd34d}
  .status-in_transit{background:linear-gradient(180deg, #ffe0e2, #ffd3d6); color:#8b1119; border-color:#f2a3aa}
  .status-delivered{background:linear-gradient(180deg, #e7f7eb, #d9f1e0); color:#176b3a; border-color:#a7e0b9}
</style>
</head>
<body>
<div class="bg-animated" aria-hidden="true">
  <span class="blob b1"></span>
  <span class="blob b2"></span>
  <span class="blob b3"></span>
</div>
<header>
  <h1><i class="ri-truck-line"></i> Package Tracker</h1>
  <span class="brand-badge"><i class="ri-flashlight-line" style="margin-right:6px;"></i>Fast • Reliable</span>
</header>
<?php if ($version_info && $version_info['update_available']): ?>
<div class="update-notice">
  Update available to version <strong><?php echo h($version_info['latest']); ?></strong> (current: <?php echo h($version_info['current']); ?>). <a href="admin/index.php"><button class="btn-secondary">Update</button></a>
</div>
<?php endif; ?>
<main>
  <div class="card" style="margin-bottom:16px;">
    <form method="get" class="field">
      <label for="tracking"><strong><i class="ri-hashtag" style="margin-right:6px;"></i>Find package by Tracking #</strong></label>
      <input type="text" id="tracking" name="tracking" placeholder="e.g. PKG-12345" value="<?=h($tracking)?>">
      <button type="submit"><i class="ri-search-line"></i>Search</button>
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
          <img src="<?=h((string)$pkg['image_path'])?>" alt="Image" style="max-width:300px; margin-top:10px; border-radius:12px; box-shadow: var(--shadow);">
        <?php endif; ?>
        <p><strong>Status:</strong> <span class="status-badge status-<?=h((string)$pkg['status'])?>" id="statusText"><?=h((string)($pkg['status'] ?: ''))?></span></p>
      </div>
    </div>
  </div>

  <!-- Progress widget -->
  <div id="progressCard" class="progress-card" style="margin-bottom:16px; display:none;">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <strong style="display:flex; align-items:center; gap:8px;"><i class="ri-timer-flash-line"></i> Route progress</strong>
      <span class="status-badge" id="statusBadge"></span>
    </div>
    <div class="progress" style="margin-top:10px;">
      <div class="progress-fill" id="progressFill"></div>
    </div>
    <div class="row" style="justify-content:space-between; margin-top:8px;">
      <span id="startLabel" class="muted">Start</span>
      <span class="muted">≈ <span id="totalKm">0</span> km</span>
      <span id="destLabel" class="muted">Destination</span>
    </div>
  </div>

  <div id="map"></div>

  <div class="card" style="margin-top:16px;">
    <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-route-line"></i> Movement history</h3>
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
      const historyArr = <?=json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
      const hasPoint = pkg.last_lat !== null && pkg.last_lng !== null;

      const map = L.map('map');
      const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      function toRad(x){ return x * Math.PI / 180; }
      function haversine(a,b){
        if(!a || !b) return 0;
        const R=6371; // km
        const dLat=toRad(b[0]-a[0]);
        const dLng=toRad(b[1]-a[1]);
        const s = Math.sin(dLat/2)**2 + Math.cos(toRad(a[0]))*Math.cos(toRad(b[0]))*Math.sin(dLng/2)**2;
        return 2*R*Math.atan2(Math.sqrt(s), Math.sqrt(1-s));
      }
      async function geocode(q){
        if(!q) return null;
        try{
          const r = await fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(q), { headers:{ 'Accept':'application/json' }});
          const arr = await r.json();
          if(!arr.length) return null;
          return [parseFloat(arr[0].lat), parseFloat(arr[0].lon)];
        }catch{ return null; }
      }

      // markers
      const boundsPts = [];
      if (hasPoint) {
        const m = L.marker([pkg.last_lat, pkg.last_lng]).addTo(map);
        const addr = pkg.last_address ? `<br><small>${pkg.last_address}</small>` : '';
        m.bindPopup(`<b>${pkg.tracking_number}</b>${addr}`);
        boundsPts.push([pkg.last_lat, pkg.last_lng]);
      }

      // Plot history points + path
      if (Array.isArray(historyArr) && historyArr.length) {
        const pathPts = historyArr.slice().reverse().map(r => [parseFloat(r.lat), parseFloat(r.lng)]);
        for (const p of pathPts) {
          L.circleMarker(p, { radius:4, color:'#D40511', weight:1, fillColor:'#FFCC00', fillOpacity:0.8 }).addTo(map);
          boundsPts.push(p);
        }
        if (pathPts.length >= 2) {
          L.polyline(pathPts, { color:'#D40511', weight:3, opacity:0.65 }).addTo(map);
        }
      }

      // Progress + start/dest route
      (async ()=>{
        const startQ = (pkg.arriving||'').trim();
        const destQ  = (pkg.destination||'').trim();
        if (!startQ || !destQ) {
          if(boundsPts.length){ map.fitBounds(boundsPts, { padding:[30,30] }); } else { map.setView([31.0461, 34.8516], 7); }
          return;
        }
        const startLL = await geocode(startQ);
        const destLL  = await geocode(destQ);
        if (startLL) { boundsPts.push(startLL); }
        if (destLL)  { boundsPts.push(destLL); }

        // Fancy icons
        const startIcon = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#16a34a;border:2px solid #0f7a37;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});
        const destIcon  = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#ef4444;border:2px solid #b91c1c;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});
        if (startLL) L.marker(startLL, {icon:startIcon}).addTo(map).bindTooltip('Start', {permanent:false});
        if (destLL)  L.marker(destLL,  {icon:destIcon}).addTo(map).bindTooltip('Destination', {permanent:false});
        if (startLL && destLL) {
          L.polyline([startLL, destLL], { color:'#111', weight:3, opacity:0.5, dashArray:'6 6' }).addTo(map);
        }

        // Progress compute
        const totalKm = (startLL && destLL) ? haversine(startLL, destLL) : 0;
        const curLL = hasPoint ? [pkg.last_lat, pkg.last_lng] : null;
        let progress = 0;
        if (curLL && totalKm > 0) {
          let done = haversine(startLL, curLL);
          let toDest = haversine(curLL, destLL);
          if (toDest <= 5) progress = 100; else progress = Math.max(0, Math.min(100, (done/totalKm)*100));
        }
        // Update UI
        const pc = document.getElementById('progressCard');
        const pf = document.getElementById('progressFill');
        const tkm = document.getElementById('totalKm');
        const sl  = document.getElementById('startLabel');
        const dl  = document.getElementById('destLabel');
        const sb  = document.getElementById('statusBadge');
        if (pc && pf && tkm){
          pc.style.display = 'block';
          pf.style.width = progress.toFixed(0) + '%';
          pf.title = progress.toFixed(0) + '%';
          tkm.textContent = Math.round(totalKm);
          sl.textContent = startQ;
          dl.textContent = destQ;
          const st = (pkg.status||'').toLowerCase();
          sb.textContent = st || (progress>=100?'delivered': (progress>0?'in_transit':'created'));
          sb.className = 'status-badge ' + (st?('status-'+st): (progress>=100?'status-delivered': (progress>0?'status-in_transit':'status-created')));
        }

        if (boundsPts.length) map.fitBounds(boundsPts, { padding:[30,30] }); else map.setView([31.0461, 34.8516], 7);
      })();
    })();
  </script>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
<script>
  (function(){
    const footer = document.querySelector('footer');
    const mainEl = document.querySelector('main');
    if(!footer) return;
    function applyPad(){
      const h = footer.getBoundingClientRect().height || 0;
      document.body.style.paddingBottom = (h + 16) + 'px';
      if (mainEl) mainEl.style.marginBottom = (h + 16) + 'px';
    }
    // Initial + events
    applyPad();
    window.addEventListener('load', applyPad, { passive:true });
    window.addEventListener('resize', applyPad, { passive:true });
    window.addEventListener('orientationchange', applyPad, { passive:true });
    document.addEventListener('DOMContentLoaded', applyPad, { passive:true });
    // React to footer size changes
    if (window.ResizeObserver) {
      const ro = new ResizeObserver(applyPad);
      ro.observe(footer);
    } else {
      // Fallback periodic check (older browsers)
      let lastH = 0;
      setInterval(()=>{ const h = footer.offsetHeight || 0; if (h !== lastH){ lastH=h; applyPad(); } }, 500);
    }
  })();
</script>
</body>
</html>
