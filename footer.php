<?php
/**
 * Footer with version and last update info.
 */

function get_version(): string {
    $version = exec('git describe --tags 2>/dev/null');
    return $version ? trim($version) : 'dev';
}

function get_last_update(): string {
    $date = exec('git log -1 --format=%ci 2>/dev/null');
    return $date ? trim($date) : date('Y-m-d H:i:s');
}
?>
<footer style="text-align: center; padding: 10px; background: #f0f0f0; margin-top: 20px;">
    <p>OpenParcelTracker v<?= get_version() ?> | Last updated: <?= get_last_update() ?></p>
</footer>