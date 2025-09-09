<?php
/**
 * Footer with version and last update info.
 */
?>
<footer style="position:relative; z-index:1; text-align:center; padding:14px 10px; background: rgba(255,255,255,0.9); backdrop-filter: blur(6px); border-top: 1px solid rgba(0,0,0,0.06); margin-top: 20px;">
    <p style="margin:0; color:#0b0b0b;">
        Service developed by <a href="https://buyreadysite.com" target="_blank" style="color:#D40511; text-decoration:underline;">Buyreadysite.com</a>
        | OpenParcelTracker v<?= get_version() ?> | Last updated: <?= get_last_update() ?>
        <?php if (isset($_SESSION['uid'])): ?>
        | <a href="javascript:location.reload()" style="color:#D40511; text-decoration:underline;">Check for updates</a>
        <?php endif; ?>
        <?php if (isset($_SESSION['uid']) && isset($version_info) && $version_info && $version_info['update_available']): ?>
        | <button id="updateBtnFooter" title="Update from repository" style="padding:8px 12px; border:0; border-radius:10px; background: linear-gradient(180deg, #D40511, #b1040e); color:#fff; cursor:pointer; box-shadow:0 6px 14px rgba(212,5,17,.25);">Update to <?php echo htmlspecialchars($version_info['latest']); ?></button>
        <a href="?force_update=1" style="margin-left:10px; color:#7c5a00; background: linear-gradient(90deg, #fff7cc, #ffeab3); padding:4px 8px; border-radius:8px; text-decoration:none; border:1px solid #ffd34d;">(force)</a>
        <?php endif; ?>
    </p>
    <?php if (isset($_SESSION['uid']) && isset($version_info) && $version_info && $version_info['update_available']): ?>
    <p style="color:#7c5a00; margin:6px 0 0;">Update to <?php echo htmlspecialchars($version_info['latest']); ?> available</p>
    <?php endif; ?>
</footer>