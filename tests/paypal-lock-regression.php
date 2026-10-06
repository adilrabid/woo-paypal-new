<?php
require __DIR__ . '/paypal-lock-fixture.php';
require dirname(__DIR__) . '/woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-lock.php';
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Lock;
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
foreach (array('wcpprog_subscription_create_test', 'wcpprog_payment_lock_test') as $key) {
    $first = PayPal_Lock::acquire($key);
    check($first && !PayPal_Lock::acquire($key), 'Active owner excludes another request');
    PayPal_Lock::release($key, 'foreign-token');
    check(!PayPal_Lock::acquire($key), 'Foreign owner cannot release lock');
    PayPal_Lock::release($key, $first);
    check(!isset($GLOBALS['locks'][$key]), 'Owner releases lock');
    $GLOBALS['locks'][$key] = time() - PayPal_Lock::TTL - 1;
    $replacement = PayPal_Lock::acquire($key);
    check($replacement, 'Recover legacy timestamp lock after hard kill');
    $expired = (time() - PayPal_Lock::TTL - 1) . ':dead-owner';
    $GLOBALS['locks'][$key] = $expired;
    $replacement = PayPal_Lock::acquire($key);
    PayPal_Lock::release($key, $expired);
    check($GLOBALS['locks'][$key] === $replacement, 'Old shutdown cannot delete replacement');
    $GLOBALS['locks'][$key] = $expired;
    $winner = null;
    $GLOBALS['wpdb']->before_update = static function () use ($key, &$winner) { $winner = PayPal_Lock::acquire($key); };
    check(!PayPal_Lock::acquire($key), 'Losing stale-lock contender must not enter critical section');
    check($winner && $GLOBALS['locks'][$key] === $winner, 'Exactly one stale-lock contender wins');
    PayPal_Lock::release($key, $winner);
}
echo "PayPal lock regression checks passed.\n";
