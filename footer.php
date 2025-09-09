<?php
/**
 * Footer with version and last update info.
 */
?>
<footer style="text-align: center; padding: 10px; background: #f0f0f0; margin-top: 20px;">
    <p>Service developed by <a href="https://buyreadysite.com" target="_blank">Buyreadysite.com</a> | OpenParcelTracker v<?= get_version() ?> | Last updated: <?= get_last_update() ?> | <a href="/admin/index.php">Update</a> | <a href="/admin/index.php?force_update=1">Force Update</a></p>
    <?php if (isset($version_info) && $version_info && $version_info['update_available']): ?>
    <p style="color: yellow;">Update to <?php echo htmlspecialchars($version_info['latest']); ?> available</p>
    <?php endif; ?>
</footer>