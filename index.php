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

// English-first localization with broader country coverage
function normalize_en_locale(string $loc): string {
    $loc = str_replace('-', '_', trim($loc));
    if ($loc === '' || strtolower($loc) === 'en') return 'en_US';
    if (preg_match('/^en_([a-z]{2}|[A-Z]{2})$/', $loc, $m)) return 'en_' . strtoupper($m[1]);
    return 'en_US';
}
function detect_locale(): string {
    $al = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if (preg_match('/en-([a-z]{2})/', $al, $m)) return normalize_en_locale('en_' . $m[1]);
    if (str_contains($al, 'en')) return 'en_US';
    // non-English -> pick en_GB for international style
    return 'en_GB';
}
function get_locale(): string {
    $lang = $_GET['lang'] ?? '';
    $lang = is_string($lang) ? trim($lang) : '';
    $allowed = ['en_US','en_GB','en_CA','en_AU','en_NZ','en_IE','en_IN','en_SG','en_ZA','en_PH','en_HK','en_MY','en_AE','en_EG'];
    if ($lang !== ''){
        $norm = normalize_en_locale($lang);
        if (in_array($norm, $allowed, true)) return $norm;
    }
    return detect_locale();
}
function utc_offset_str(): string {
    $dt = new DateTime('now'); $off = $dt->getOffset();
    $sign = $off >= 0 ? '+' : '-';
    $off = abs($off); $h = intdiv($off, 3600); $m = intdiv($off % 3600, 60);
    return sprintf('UTC%s%02d:%02d', $sign, $h, $m);
}
function format_day_label(string $day, string $locale): string {
    $ts = strtotime($day);
    if (!$ts) return $day;
    if (class_exists(IntlDateFormatter::class)){
        $fmt = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE);
        $fmt->setPattern('EEEE, d MMMM y');
        return $fmt->format($ts);
    }
    // Fallback
    @setlocale(LC_TIME, $locale . '.utf8', $locale, 'en_US.utf8');
    return strftime('%A, %d %B %Y', $ts);
}
function format_time_local(int $ts, string $locale): string {
    $isUS = str_starts_with($locale, 'en_US');
    return $isUS ? strtolower(date('g:i a', $ts)) : date('H:i', $ts);
}
function service_area_from_address(?string $addr): string {
    $addr = trim((string)$addr);
    if ($addr === '') return '';
    $parts = array_values(array_filter(array_map('trim', explode(',', $addr)), fn($x)=>$x!==''));
    $n = count($parts);
    if ($n === 0) return '';
    $take = array_slice($parts, max(0, $n-3));
    return implode(' – ', $take);
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
  body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; padding:0; color:var(--text); min-height: 100vh; display: flex; flex-direction: column; background: #fff; padding-bottom: 0;}

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
  /* Logo pill for contrast on gradient */
  .logo-pill{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; background:#fff; border-radius:12px; box-shadow:0 6px 14px rgba(0,0,0,0.15); }
  .brand-logo{ height:22px; width:auto; display:block; }

  main{position: relative; z-index:1; padding:20px; max-width:1100px; margin:0 auto; width:100%; flex:1;}
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
  .progress-stops{ position:absolute; inset:0; pointer-events:none; }
  .progress-stop{ position:absolute; top:50%; transform:translate(-50%,-50%); width:10px; height:10px; border-radius:50%; background:#fff; border:2px solid var(--brand-red); box-shadow:0 0 0 2px rgba(255,255,255,0.7) }
  .progress-stop.past{ background: var(--brand-red); border-color: #b1040e; }
  .progress-stop.current{ background:#ffcd00; border-color:#e6b800; }
  .progress-stop.future{ background:#fff; border-color: rgba(0,0,0,0.2); }
  .progress-stop .lbl{ position:absolute; bottom:14px; left:50%; transform:translateX(-50%); font-size:11px; color:#333; background:rgba(255,255,255,0.85); padding:2px 6px; border-radius:8px; border:1px solid rgba(0,0,0,0.08); white-space:nowrap; }
  .progress-cursor{ position:absolute; top:50%; transform:translate(-50%,-50%); width:0; height:0; border-left:6px solid transparent; border-right:6px solid transparent; border-bottom:10px solid #111; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.2)); }
  .progress-cursor-label{ position:absolute; top:-22px; left:50%; transform:translateX(-50%); font-size:11px; color:#111; background:rgba(255,255,255,0.95); padding:2px 6px; border-radius:8px; border:1px solid rgba(0,0,0,0.08); white-space:nowrap; }

  /* Responsive two-column layout for package view */
  .layout-2{ display:block; }
  @media (min-width: 900px){
    .layout-2{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; align-items:stretch; }
  }
  .info-grid{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:8px 16px; }
  .info-grid p{ margin:6px 0; }
  .info-grid .full{ grid-column: 1 / -1; }
  .pkg-img{ width:100%; max-height:220px; object-fit:cover; border-radius:12px; box-shadow: var(--shadow); }
  .details-toggle{ margin-top:8px; }
  .details-toggle > summary{ cursor:pointer; list-style:none; }
  .details-toggle > summary::-webkit-details-marker{ display:none; }
  .details-toggle > summary::before{ content: '▸'; display:inline-block; margin-right:6px; transition: transform .2s; }
  .details-toggle[open] > summary::before{ transform: rotate(90deg); }
  /* Make right column map fill equal height as left column on desktop */
  @media (min-width: 900px){
    .col-right > .card{ height:100%; display:flex; flex-direction:column; }
    .col-right #map{ flex:1 1 auto; height:auto; min-height:420px; }
  }

  /* DHL-like timeline (wide on desktop) */
  .timeline{ position:relative; }
  .tl-day{ position:relative; padding:0; margin:0; border-top:1px solid #e6e9ee; }
  .tl-day:first-child{ border-top:0; }
  .tl-day > summary.tl-day-header{ list-style:none; cursor:pointer; position: sticky; top: 0; z-index: 2; background: rgba(255,255,255,0.9); backdrop-filter: blur(6px); padding:10px 0; font-weight:700; color:#111; display:flex; align-items:center; gap:8px; }
  .tl-day > summary.tl-day-header::-webkit-details-marker{ display:none; }
  .tl-day > summary.tl-day-header::after{ content:'▸'; margin-left:8px; transition: transform .2s; }
  .tl-day[open] > summary.tl-day-header::after{ transform: rotate(90deg); }
  .tl-rows{ padding:4px 0 8px; }
  .tl-row{ display:grid; grid-template-columns: 160px 28px 1fr; gap:12px; align-items:start; padding:10px 0; }
  @media (max-width: 720px){ .tl-row{ grid-template-columns: 90px 24px 1fr; } }
  .tl-time{ color:#5b6470; font-size:13px; white-space:nowrap; }
  .tl-line{ position:relative; }
  .tl-line::before{ content:""; position:absolute; left:50%; top:-14px; bottom:-14px; width:2px; transform:translateX(-50%); background:#e6e9ee; }
  .tl-dot{ position:relative; z-index:1; display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#fff; border:2px solid #c5ced8; margin-left:3px; box-shadow:0 0 0 4px rgba(255,255,255,0.8); }
  .tl-dot i{ font-size:14px; }
  .tl-row.delivered .tl-dot{ background:#16a34a; border-color:#0f7a37; color:#fff; }
  .tl-row.courier .tl-dot{ background:#ffcd00; border-color:#e6b800; color:#111; }
  .tl-row.departed .tl-dot{ background:#D40511; border-color:#b1040e; color:#fff; }
  .tl-row.arrived .tl-dot{ background:#fff; border-color:#D40511; color:#D40511; }
  .tl-row.customs .tl-dot{ background:#fff; border-color:#ff7a00; color:#ff7a00; }
  .tl-row.intransit .tl-dot{ background:#fff; border-color:#ff7a00; color:#ff7a00; }
  .tl-title{ font-weight:700; }
  .tl-title.delivered{ color:#0f7a37; }
  .tl-title.intransit{ color:#b1040e; }
  .tl-sub{ font-size:13px; color:#333; margin-top:2px; text-transform: uppercase; letter-spacing:.2px; }
  .tl-meta{ font-size:12px; color:#5b6470; margin-top:3px; }
  .tl-meta a{ color:#b1040e; text-decoration:underline; }
  .tl-count{ display:inline-block; margin-left:8px; font-size:12px; color:#5b6470; background:#f1f3f7; border:1px solid #e3e6ea; border-radius:999px; padding:2px 8px; }

  /* Print styles tweak for timeline */
  @media print {
    .tl-day > summary.tl-day-header{ position: static; background:#fff !important; }
    .tl-line::before{ background:#bbb !important; }
    .tl-dot{ box-shadow:none !important; }
  }
</style>
<?php
// Crisp: emit plain script tags in HEAD if enabled and matches schedule (server time)
$cr_enabled   = setting_get('crisp_enabled', '0') === '1';
$cr_websiteId = trim((string)setting_get('crisp_website_id', ''));
$cr_sched_on  = setting_get('crisp_schedule_enabled', '0') === '1';
$cr_days_str  = (string)setting_get('crisp_days', '1,2,3,4,5'); // 0..6 (Sun..Sat)
$cr_start_str = (string)setting_get('crisp_hours_start', '09:00');
$cr_end_str   = (string)setting_get('crisp_hours_end', '18:00');

function _hm_to_minutes(string $s): int { $p = explode(':', $s); $h = (int)($p[0] ?? 0); $m = (int)($p[1] ?? 0); $h = max(0, min(23, $h)); $m = max(0, min(59, $m)); return $h*60 + $m; }

$cr_should_emit = false;
if ($cr_enabled && $cr_websiteId !== '') {
    if (!$cr_sched_on) {
        $cr_should_emit = true;
    } else {
        $allowedDays = array_values(array_filter(array_map('trim', explode(',', $cr_days_str)), fn($x)=>$x!==''));
        $allowed = array_map('intval', $allowedDays);
        $dow = (int)date('w'); // 0..6 Sun..Sat
        if (in_array($dow, $allowed, true)) {
            $nowM = (int)date('G') * 60 + (int)date('i');
            $sM = _hm_to_minutes($cr_start_str);
            $eM = _hm_to_minutes($cr_end_str);
            $within = ($eM >= $sM) ? ($nowM >= $sM && $nowM <= $eM) : ($nowM >= $sM || $nowM <= $eM);
            $cr_should_emit = $within;
        }
    }
}

if ($cr_should_emit) {
    echo "\n<!-- Crisp chat -->\n";
    echo '<script>window.$crisp=[];window.CRISP_WEBSITE_ID=' . json_encode($cr_websiteId) . ';</script>' . "\n";
    echo '<script data-cfasync="false" src="https://client.crisp.chat/l.js" async></script>' . "\n";
} else {
    $reason = !$cr_enabled ? 'disabled' : (($cr_websiteId==='') ? 'no-id' : ($cr_sched_on ? 'schedule-mismatch' : 'unknown'));
    echo "\n<!-- Crisp not emitted: " . htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . " -->\n";
}
?>
</head>
<body>
<div class="bg-animated" aria-hidden="true">
  <span class="blob b1"></span>
  <span class="blob b2"></span>
  <span class="blob b3"></span>
</div>
<header>
  <div style="display:flex; align-items:center; gap:10px;">
    <span class="logo-pill"><img src="dhl-logo.svg" alt="Logo" class="brand-logo"></span>
    <h1>Package Tracker</h1>
  </div>
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
  <!-- Toolbar: print -->
  <div id="pkgToolbar" class="row" style="justify-content:flex-end; margin: -4px 0 8px 0;">
    <button id="printBtn" class="btn-secondary" title="Print package details"><i class="ri-printer-line"></i> Print</button>
  </div>

  <!-- Progress widget placed above the two columns -->
  <div id="progressCard" class="progress-card" style="margin-bottom:16px; display:none;">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <strong style="display:flex; align-items:center; gap:8px;"><i class="ri-timer-flash-line"></i> Route progress</strong>
      <span class="status-badge" id="statusBadge"></span>
    </div>
    <div class="progress" style="margin-top:10px;">
      <div class="progress-fill" id="progressFill"></div>
      <div class="progress-stops" id="progressStops"></div>
      <div class="progress-cursor" id="progressCursor" title=""></div>
    </div>
    <div class="row" style="justify-content:space-between; margin-top:8px;">
      <span id="startLabel" class="muted">Start</span>
      <span class="muted">≈ <span id="totalKm">0</span> km</span>
      <span id="destLabel" class="muted">Destination</span>
    </div>
  </div>

  <div class="layout-2">
    <div class="col-left">
      <div class="card">
        <div class="info-grid">
          <p class="full"><strong>Tracking #:</strong> <span class="badge"><?=h($pkg['tracking_number'])?></span></p>
          <p><strong>Title:</strong> <?=h((string)$pkg['title'])?></p>
          <p><strong>Delivery option:</strong> <?=h((string)$pkg['delivery_option'])?></p>
          <p><strong>Status:</strong> <span class="status-badge status-<?=h((string)$pkg['status'])?>" id="statusText"><?=h((string)($pkg['status'] ?: ''))?></span></p>
          <p><strong>Arriving:</strong> <?=h((string)$pkg['arriving'])?></p>
          <p><strong>Destination:</strong> <?=h((string)$pkg['destination'])?></p>
          <p class="full"><span class="muted">Updated: <?=h((string)$pkg['updated_at'])?></span></p>

          <?php if ($pkg['last_address']): ?>
            <p class="full"><strong>Current address:</strong> <?=h((string)$pkg['last_address'])?></p>
          <?php endif; ?>

          <?php if ($pkg['last_lat'] !== null && $pkg['last_lng'] !== null): ?>
            <p><strong>Coords:</strong> <?=h((string)$pkg['last_lat'])?>, <?=h((string)$pkg['last_lng'])?></p>
          <?php else: ?>
            <p class="muted">No coordinates yet.</p>
          <?php endif; ?>

          <?php if (!empty($pkg['image_path'])): ?>
            <div class="full"><img src="<?=h((string)$pkg['image_path'])?>" alt="Image" class="pkg-img"></div>
          <?php endif; ?>

          <?php if (!empty(trim((string)$pkg['description']))): ?>
            <details class="details-toggle full">
              <summary><strong>Details</strong></summary>
              <div style="margin-top:6px; white-space:pre-wrap;"><?=h((string)$pkg['description'])?></div>
            </details>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-right">
      <div class="card" style="padding:0;">
        <div id="map"></div>
      </div>
    </div>
  </div>

  <!-- Movement history timeline -->
  <div class="card" style="margin-top:16px;">
    <h3 style="display:flex; align-items:center; gap:8px;"><i class="ri-route-line"></i> Movement history</h3>
    <?php if (!$history): ?>
      <p class="muted">No history yet.</p>
    <?php else: ?>
      <div class="timeline">
        <?php
          // Group by day (DESC order already)
          $groups = [];
          foreach ($history as $row) {
            $ts = strtotime((string)$row['created_at']);
            $dayKey = $ts ? date('Y-m-d', $ts) : 'unknown';
            $groups[$dayKey][] = $row;
          }
          krsort($groups);

          $locale = get_locale();
          $utcStr = utc_offset_str();

          // Precompute travel time since previous point (global, not reset per day)
          $durById = [];
          for ($i = 0; $i < count($history) - 1; $i++) {
              $curTs = strtotime((string)$history[$i]['created_at']);
              $prevTs = strtotime((string)$history[$i+1]['created_at']);
              if ($curTs && $prevTs && $curTs >= $prevTs) {
                  $durById[(int)$history[$i]['id']] = $curTs - $prevTs; // seconds
              }
          }
          $formatDur = function(int $sec): string {
              $d = intdiv($sec, 86400); $sec %= 86400;
              $h = intdiv($sec, 3600);  $sec %= 3600;
              $m = intdiv($sec, 60);
              $parts = [];
              if ($d>0) $parts[] = $d . 'd';
              if ($h>0) $parts[] = $h . 'h';
              if ($m>0 || !$parts) $parts[] = $m . 'm';
              return implode(' ', $parts);
          };

          $classify = function(string $note): array {
            $n = mb_strtolower($note);
            $cls = 'intransit'; $icon = 'ri-alert-line'; // triangle-ish for in-transit
            if ($n === '') { return [$cls, $icon]; }
            if (str_contains($n, 'достав') || str_contains($n, 'deliver')) { return ['delivered', 'ri-check-line']; }
            if (str_contains($n, 'кур') || str_contains($n, 'courier')) { return ['courier', 'ri-truck-line']; }
            if (str_contains($n, 'прибув') || str_contains($n, 'arriv')) { return ['arrived', 'ri-inbox-archive-line']; }
            if (str_contains($n, 'залиш') || str_contains($n, 'depart') || str_contains($n, 'left')) { return ['departed', 'ri-flight-takeoff-line']; }
            if (str_contains($n, 'митн') || str_contains($n, 'custom')) { return ['customs', 'ri-shield-check-line']; }
            return [$cls, $icon];
          };
          $upper = function(string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper($s) : strtoupper($s); };
        ?>
        <?php foreach ($groups as $day => $rows): ?>
          <?php $label = $day !== 'unknown' ? format_day_label($day, $locale) : '—'; ?>
          <details class="tl-day" open>
            <summary class="tl-day-header"><?=$label?> <span class="muted utc-offset" style="font-weight:500;">(<?=$utcStr?>)</span></summary>
            <div class="tl-rows">
              <?php foreach ($rows as $r):
                  $tsR = strtotime((string)$r['created_at']);
                  $timeStr = $tsR ? format_time_local($tsR, $locale) : '';
                  $note = (string)($r['note'] ?? '');
                  $addr = (string)($r['address'] ?? '');
                  [$cls, $icon] = $classify($note);
                  $lat = (float)$r['lat']; $lng = (float)$r['lng'];
                  $mapsUrl = ($lat && $lng) ? ('https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng)) : '';
                  $serviceArea = service_area_from_address($addr);
                  $delta = $durById[(int)$r['id']] ?? null;
              ?>
              <div class="tl-row <?=$cls?>">
                <div class="tl-time"><?=h($timeStr)?></div>
                <div class="tl-line"><span class="tl-dot"><i class="<?=$icon?>"></i></span></div>
                <div class="tl-content">
                  <div class="tl-title <?=$cls==='delivered'?'delivered':'intransit'?>">
                    <?=h($note ?: 'Status update')?>
                  </div>
                  <?php if ($addr): ?><div class="tl-sub"><?php echo h($upper($addr)); ?></div><?php endif; ?>
                  <?php if ($serviceArea): ?><div class="tl-meta">Service area: <?=h($serviceArea)?></div><?php endif; ?>
                  <div class="tl-meta">
                    <a href="?tracking=<?=h($pkg['tracking_number'])?>">1 Unit: <?=h($pkg['tracking_number'])?></a>
                    <?php if ($mapsUrl): ?> · <a href="<?=$mapsUrl?>" target="_blank" rel="noopener">Open in Maps</a><?php endif; ?>
                    <?php if ($delta): ?> · Travel since last point: <?=h($formatDur((int)$delta))?><?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin="">
  </script>
  <script type="application/json" id="data-pkg"><?=
    json_encode(
      $pkg,
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    )
  ?></script>
  <script type="application/json" id="data-history"><?=
    json_encode(
      $history,
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    )
  ?></script>
  <script>
    (function(){
      var pkgEl = document.getElementById('data-pkg');
      var pkg = pkgEl ? JSON.parse(pkgEl.textContent || 'null') : null;
      var historyEl = document.getElementById('data-history');
      var historyArr = historyEl ? JSON.parse(historyEl.textContent || '[]') : [];
      var hasPoint = pkg && pkg.last_lat !== null && pkg.last_lng !== null;

      var map = L.map('map');
      var tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      function toRad(x){ return x * Math.PI / 180; }
      function haversine(a,b){
        if(!a || !b) return 0;
        var R=6371; // km
        var dLat=toRad(b[0]-a[0]);
        var dLng=toRad(b[1]-a[1]);
        var s = Math.pow(Math.sin(dLat/2),2) + Math.cos(toRad(a[0]))*Math.cos(toRad(b[0]))*Math.pow(Math.sin(dLng/2),2);
        return 2*R*Math.atan2(Math.sqrt(s), Math.sqrt(1-s));
      }
      function geocode(q){
        if(!q) return Promise.resolve(null);
        return fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(q), { headers:{ 'Accept':'application/json' }})
          .then(function(r){ return r.json(); })
          .then(function(arr){ if(!arr.length) return null; return [parseFloat(arr[0].lat), parseFloat(arr[0].lon)]; })
          .catch(function(){ return null; });
      }

      var boundsPts = [];

      function init(){
        var startQ = (pkg && pkg.arriving ? pkg.arriving : '').trim();
        var destQ  = (pkg && pkg.destination ? pkg.destination : '').trim();
        var p1 = startQ ? geocode(startQ) : Promise.resolve(null);
        var p2 = destQ  ? geocode(destQ)  : Promise.resolve(null);
        Promise.all([p1,p2]).then(function(res){
          var startLL = res[0];
          var destLL  = res[1];

          // Build chronological stops from history
          var stops = Array.isArray(historyArr) ? historyArr.slice().reverse().map(function(r){ return [parseFloat(r.lat), parseFloat(r.lng)]; }) : [];
          var curLL = (pkg && pkg.last_lat!==null && pkg.last_lng!==null) ? [pkg.last_lat, pkg.last_lng] : null;
          // If no history but we have current point, treat it as the first stop
          var hasHist = stops.length>0;
          var currentIsLastStop = hasHist ? (Math.abs(stops[stops.length-1][0]-(curLL?curLL[0]:NaN))<1e-6 && Math.abs(stops[stops.length-1][1]-(curLL?curLL[1]:NaN))<1e-6) : false;
          if (!hasHist && curLL) stops.push(curLL);

          // Full planned route: start -> stops -> destination
          var routeFull = [];
          if (startLL) routeFull.push(startLL);
          for (var i=0;i<stops.length;i++){ routeFull.push(stops[i]); }
          if (destLL) routeFull.push(destLL);

          // Compute piecewise distances
          function sumLegs(points){
            var sum=0; for(var i=1;i<points.length;i++){ sum += haversine(points[i-1], points[i]); } return sum;
          }
          var totalKm = (routeFull.length>=2) ? sumLegs(routeFull) : (startLL&&destLL ? haversine(startLL,destLL) : 0);

          // Distance done: start -> last stop (or 0 if none)
          var traveledPts = [];
          if (startLL) traveledPts.push(startLL);
          for (var j=0;j<stops.length;j++){ traveledPts.push(stops[j]); }
          var doneKm = (traveledPts.length>=2) ? sumLegs(traveledPts) : 0;
          var progress = (totalKm>0) ? Math.max(0, Math.min(100, (doneKm/totalKm)*100)) : 0;

          // Map: draw start/dest markers
          var startIcon = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#16a34a;border:2px solid #0f7a37;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});
          var destIcon  = L.divIcon({ className:'', html:'<div style="width:22px;height:22px;border-radius:50%;background:#ef4444;border:2px solid #b91c1c;box-shadow:0 0 0 2px #fff"></div>', iconSize:[22,22], iconAnchor:[11,11]});
          if (startLL) L.marker(startLL, {icon:startIcon}).addTo(map).bindTooltip('Start');
          if (destLL)  L.marker(destLL,  {icon:destIcon}).addTo(map).bindTooltip('Destination');

          // Full route dashed
          if (routeFull.length>=2) {
            L.polyline(routeFull, { color:'#111', weight:3, opacity:0.5, dashArray:'6 6' }).addTo(map);
          }
          // Traveled path solid red
          if (traveledPts.length>=2) {
            L.polyline(traveledPts, { color:'#D40511', weight:4, opacity:0.85 }).addTo(map);
          }
          // Mark each historical stop as small circle
          for (var k=0;k<stops.length;k++){
            L.circleMarker(stops[k], { radius:4, color:'#D40511', weight:1, fillColor:'#FFCC00', fillOpacity:0.9 }).addTo(map);
          }

          // Include current and route points in bounds
          for (var p=0;p<routeFull.length;p++) boundsPts.push(routeFull[p]);
          if (boundsPts.length) map.fitBounds(boundsPts, { padding:[30,30] }); else {
            if (curLL) map.setView(curLL, 13); else map.setView([31.0461, 34.8516], 7);
          }

          // Progress UI
          var pc = document.getElementById('progressCard');
          var pf = document.getElementById('progressFill');
          var tkm = document.getElementById('totalKm');
          var sl  = document.getElementById('startLabel');
          var dl  = document.getElementById('destLabel');
          var sb  = document.getElementById('statusBadge');
          var ps  = document.getElementById('progressStops');
          var pcursor = document.getElementById('progressCursor');
          if (pc && pf && tkm){
            pc.style.display = (startLL && destLL) ? 'block' : 'none';
            if (pc.style.display === 'block'){
              pf.style.width = progress.toFixed(0) + '%';
              pf.title = progress.toFixed(0) + '%';
              tkm.textContent = Math.round(totalKm);
              sl.textContent = startQ; dl.textContent = destQ;

              // Render stop ticks with labels and tooltips
              ps.innerHTML = '';
              if (totalKm > 0 && routeFull.length>=2){
                // cumulative distances for each route point
                var cum = 0;
                var cumList = [0];
                for (var idx=1; idx<routeFull.length; idx++){
                  cum += haversine(routeFull[idx-1], routeFull[idx]);
                  cumList.push(cum);
                }
                // Build metadata for stops from history (chronological order)
                var histChrono = Array.isArray(historyArr) ? historyArr.slice().reverse() : [];
                // For each intermediate point (exclude start=0 and dest=last)
                for (var ii=1; ii<routeFull.length-1; ii++){
                  var pct = (cumList[ii] / totalKm) * 100;
                  var dot = document.createElement('span');
                  dot.className = 'progress-stop future';
                  dot.style.left = pct + '%';
                  // Tooltip content
                  var hmeta = histChrono[ii-1] || {};
                  var tipParts = [];
                  tipParts.push('≈ ' + pct.toFixed(0) + '%');
                  if (hmeta.created_at) tipParts.push(hmeta.created_at);
                  if (hmeta.address) tipParts.push(hmeta.address);
                  if (hmeta.note) tipParts.push('Note: ' + hmeta.note);
                  dot.title = tipParts.join(' • ');
                  // Label above
                  var lbl = document.createElement('span');
                  lbl.className = 'lbl';
                  lbl.textContent = pct.toFixed(0) + '%';
                  dot.appendChild(lbl);
                  // decide past/current/future
                  if (pct < progress - 0.5) dot.className = 'progress-stop past';
                  else if (Math.abs(pct - progress) <= 0.5) dot.className = 'progress-stop current';
                  ps.appendChild(dot);
                }
                // Current position cursor (even if not exactly at stop)
                if (pcursor){
                  pcursor.style.left = Math.max(0, Math.min(100, progress)) + '%';
                  pcursor.title = 'Now ≈ ' + progress.toFixed(0) + '%';
                  // label element
                  var lbl2 = pcursor.querySelector('.progress-cursor-label');
                  if (!lbl2){
                    lbl2 = document.createElement('span');
                    lbl2.className = 'progress-cursor-label';
                    pcursor.appendChild(lbl2);
                  }
                  lbl2.textContent = 'Now ' + progress.toFixed(0) + '%';
                }
              }

              var st = (pkg && pkg.status ? String(pkg.status) : '').toLowerCase();
              var autoSt = (progress>=100?'delivered': (progress>0?'in_transit':'created'));
              sb.textContent = st || autoSt;
              sb.className = 'status-badge ' + (st?('status-'+st) : (progress>=100?'status-delivered': (progress>0?'status-in_transit':'status-created')));
            }
          }
        });
      }

      init();

      var printBtn = document.getElementById('printBtn');
      if (printBtn) {
        printBtn.addEventListener('click', function(){ window.print(); });
      }

      // Update UTC offset with client's timezone
      function pad2(s){ s=String(s); return s.length<2?('0'+s):s; }
      try {
        var mins = -new Date().getTimezoneOffset();
        var sign = mins >= 0 ? '+' : '-';
        var abs = Math.abs(mins);
        var h = pad2(Math.floor(abs / 60));
        var m = pad2(abs % 60);
        var txt = 'UTC' + sign + h + ':' + m;
        var utcOffsets = document.querySelectorAll('.utc-offset');
        for (var iii = 0; iii < utcOffsets.length; iii++) {
          utcOffsets[iii].textContent = '(' + txt + ')';
        }
      } catch (e) {}
    })();
  </script>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
