<?php
/**
 * Footer with version and last update info.
 */
?>
<footer style="text-align: center; padding: 10px; background: #f0f0f0; margin-top: 20px;">
    <p>Service developed by <a href="https://buyreadysite.com" target="_blank">Buyreadysite.com</a> | OpenParcelTracker v<?= get_version() ?> | Last updated: <?= get_last_update() ?>
    <?php if (isset($_SESSION['uid'])): ?>
    | <a href="javascript:location.reload()">Check for updates</a>
    <?php endif; ?>
    <?php if (isset($_SESSION['uid']) && isset($version_info) && $version_info && $version_info['update_available']): ?>
    | <button id="updateBtnFooter" title="Update from repository">Update to <?php echo htmlspecialchars($version_info['latest']); ?></button>
    <a href="?force_update=1" style="margin-left:10px; color: yellow;">(force)</a>
    <?php endif; ?>
    </p>
    <?php if (isset($_SESSION['uid']) && isset($version_info) && $version_info && $version_info['update_available']): ?>
    <p style="color: yellow;">Update to <?php echo htmlspecialchars($version_info['latest']); ?> available</p>
    <?php endif; ?>
</footer>