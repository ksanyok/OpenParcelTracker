<?php
/**
 * Footer with version and last update info.
 */

function get_version(): string {
    return VERSION;
}

function get_last_update(): string {
    $date = exec('git log -1 --format=%cd --date=short 2>/dev/null');
    return $date ? trim($date) : date('Y-m-d');
}
?>
<footer style="text-align: center; padding: 10px; background: #f0f0f0; margin-top: 20px;">
    <p>Service developed by <a href="https://buyreadysite.com" target="_blank">Buyreadysite.com</a> | OpenParcelTracker v<?= get_version() ?> | Last updated: <?= get_last_update() ?> | <a href="admin/index.php">Update</a></p>
</footer>