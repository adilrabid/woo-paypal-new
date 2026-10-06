<?php
/** Run: php tests/subscription-block-payment-requirements-regression.php */
define('ABSPATH', __DIR__);
define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
function WC() { return $GLOBALS['wc']; }
class WC_Product {
    public function __construct(private $type) {}
    public function get_type() { return $this->type; }
}
require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-related.php';
$handler = (new ReflectionClass(WCPPROG_Subscription_Related::class))->newInstanceWithoutConstructor();
$GLOBALS['wc'] = (object) array('cart' => null);
if ($handler->get_subscription_payment_requirements()) { throw new RuntimeException('No restriction without a cart'); }
$cart = new class {
    public $items = array();
    public function get_cart() { return $this->items; }
};
$GLOBALS['wc']->cart = $cart;
foreach (array(array(), array('simple'), array('subscription'), array('wcpprog_subscription'), array('simple', 'wcpprog_subscription')) as $types) {
    $cart->items = array_map(static fn($type) => array('data' => new WC_Product($type)), $types);
    $required = in_array('wcpprog_subscription', $types, true) ? array(WCPPROG_Subscription_Related::PAYMENT_REQUIREMENT) : array();
    if ($handler->get_subscription_payment_requirements() !== $required) { throw new RuntimeException('Wrong payment requirement for cart'); }
}
$cart->items = array(array('data' => new WC_Product('simple')));
if ($handler->get_subscription_payment_requirements()) { throw new RuntimeException('Restriction persists after subscription removal'); }
echo "Subscription block payment requirements regression checks passed.\n";
