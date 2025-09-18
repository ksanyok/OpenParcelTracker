<?php
/**
 * Footer with version and last update info.
 */
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$host = preg_replace('/:\\d+$/', '', (string)$host);
if ($host === '') { $host = 'this site'; }
$year = date('Y');
?>
<footer style="text-align:center; padding:14px 10px; background: rgba(255,255,255,0.9); backdrop-filter: blur(6px); border-top: 1px solid rgba(0,0,0,0.06);">
    <div style="display:inline-flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:center;">
        <img src="<?= (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') === 0) ? '../dhl-logo.svg' : 'dhl-logo.svg' ?>" alt="Logo" style="height:18px; width:auto; display:block; filter:saturate(110%);"/>
        <p style="margin:0; color:#0b0b0b;">
            &copy; <?=$year?> <?=htmlspecialchars($host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>
            | OpenParcelTracker v<?= get_version() ?> | Last updated: <?= get_last_update() ?>
            <?php if (isset($_SESSION['uid'])): ?>
            | <a href="javascript:location.reload()" style="color:#D40511; text-decoration:underline;">Check for updates</a>
            <?php endif; ?>
            <?php if (isset($_SESSION['uid']) && isset($version_info) && $version_info && $version_info['update_available']): ?>
            | <button id="updateBtnFooter" title="Update from repository" style="padding:8px 12px; border:0; border-radius:10px; background: linear-gradient(180deg, #D40511, #b1040e); color:#fff; cursor:pointer; box-shadow:0 6px 14px rgba(212,5,17,.25);">Update to <?php echo htmlspecialchars($version_info['latest']); ?></button>
            <a href="?force_update=1" style="margin-left:10px; color:#7c5a00; background: linear-gradient(90deg, #fff7cc, #ffeab3); padding:4px 8px; border-radius:8px; text-decoration:none; border:1px solid #ffd34d;">(force)</a>
            <?php endif; ?>
        </p>
    </div>
    <?php if (isset($_SESSION['uid']) && isset($version_info) && $version_info && $version_info['update_available']): ?>
    <p style="color:#7c5a00; margin:6px 0 0;">Update to <?php echo htmlspecialchars($version_info['latest']); ?> available</p>
    <?php endif; ?>
</footer>
<?php
// Inject Crisp widget on public pages if enabled
$inAdmin = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') === 0);
$enabled = setting_get('crisp_enabled', '0') === '1';
$websiteId = trim((string)setting_get('crisp_website_id', ''));
if (!$inAdmin && $enabled && $websiteId !== '') {
    // Read schedule config but apply it in the browser using local time
    $schedEnabled = setting_get('crisp_schedule_enabled', '0') === '1';
    $daysStr = (string)setting_get('crisp_days', '1,2,3,4,5'); // 0=Sun .. 6=Sat (as saved by admin UI)
    $startStr = (string)setting_get('crisp_hours_start', '09:00');
    $endStr   = (string)setting_get('crisp_hours_end', '18:00');

    $widEsc = htmlspecialchars($websiteId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $schedFlag = $schedEnabled ? 'true' : 'false';
    $daysJs = htmlspecialchars($daysStr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $startJs = htmlspecialchars($startStr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $endJs   = htmlspecialchars($endStr,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Always emit a tiny bootstrapper; it will decide (by client local time) whether to load Crisp.
    echo "\n<script>(function(){\n".
         "var WID='".$widEsc."';\n".
         "var SCHED=".$schedFlag.";\n".
         "var DAYS='".$daysJs."';\n".
         "var START='".$startJs."';\n".
         "var END='".$endJs."';\n".
         "function loadCrisp(){\n".
         "  window.$crisp=[]; window.CRISP_WEBSITE_ID=WID;\n".
         "  var d=document,s=d.createElement('script'); s.src='https://client.crisp.chat/l.js'; s.async=1; d.getElementsByTagName('head')[0].appendChild(s);\n".
         "}\n".
         "if(!SCHED){ loadCrisp(); return; }\n".
         "function parseHM(t){ var p=(t||'').split(':'); var h=parseInt(p[0]||'0',10)||0, m=parseInt(p[1]||'0',10)||0; if(h<0)h=0; if(h>23)h=23; if(m<0)m=0; if(m>59)m=59; return h*60+m; }\n".
         "var allowed=new Set((DAYS||'').split(',').map(function(x){return parseInt(x,10);}).filter(function(n){return !isNaN(n);}));\n".
         "var now=new Date(); var dow=now.getDay(); // 0..6, 0=Sun\n".
         "if(!allowed.has(dow)) return;\n".
         "var mins=now.getHours()*60+now.getMinutes(); var sM=parseHM(START), eM=parseHM(END);\n".
         "var within = (eM>=sM) ? (mins>=sM && mins<=eM) : (mins>=sM || mins<=eM);\n".
         "if(within){ loadCrisp(); }\n".
         "})();</script>\n";
}
?>