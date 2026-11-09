<?php
// Run: php tests/transfer-smoke.php. No WordPress database required.
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-wpvs-transfer.php';
function transfer_check($expected, $actual, $label) {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
transfer_check(true, WPVS_Transfer::valid_uid(str_repeat('a', 64)), 'valid event uid');
transfer_check(false, WPVS_Transfer::valid_uid(str_repeat('g', 64)), 'reject invalid event uid');
transfer_check(false, WPVS_Transfer::valid_uid(str_repeat('a', 63)), 'reject short event uid');
$uid_a = WPVS_Transfer::legacy_uid('11111111-1111-4111-8111-111111111111', 'events', 42);
$uid_b = WPVS_Transfer::legacy_uid('11111111-1111-4111-8111-111111111111', 'events', 42);
$uid_c = WPVS_Transfer::legacy_uid('22222222-2222-4222-8222-222222222222', 'events', 42);
transfer_check($uid_a, $uid_b, 'legacy ID deterministic');
transfer_check(false, $uid_a === $uid_c, 'legacy IDs isolated by source instance');
transfer_check(64, strlen($uid_a), 'legacy ID sha256 length');
echo "OK: transfer ID validation and deterministic legacy IDs\n";
