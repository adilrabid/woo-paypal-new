=== Payment Gateway for PayPal Pro & PayPal Checkout for WooCommerce ===
Contributors: wp.insider, wpecommerce
Donate link: https://wp-ecommerce.net/woocommerce-paypal-checkout-paypal-pro
Tags: paypal, paypal pro, woocommerce, paypal checkout, credit card
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 4.0.0
License: GPL2 or Later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Easily add PayPal Pro and PayPal Checkout payment gateways to WooCommerce. Accept credit cards on-site or offer the latest PayPal payment buttons.

== Description ==

This plugin adds two PayPal payment gateway options to your WooCommerce store:

**PayPal Pro (On-Site Credit Card Checkout)**

Accept credit card payments directly on your checkout page. Your customers enter their card details on your site and never leave to complete the transaction. The credit card checkout experience is seamless and smooth.

The following video shows the credit card checkout experience:

https://www.youtube.com/watch?v=PAZRba8Tp74

**PayPal Checkout (Latest PayPal Payment Buttons)**

Offer the latest PayPal payment buttons on your checkout and cart pages. Your customers can pay using their PayPal account, credit/debit card, Venmo, Pay Later, and other payment methods supported by PayPal. The plugin uses PayPal's Commerce Platform (PPCP) to provide a modern and secure checkout experience.

You can enable one or both payment gateways depending on your needs.

**Features**

* Two payment gateways in one plugin: PayPal Pro and PayPal Checkout.
* On-site credit card checkout via PayPal Pro.
* Latest PayPal payment buttons via PayPal Checkout (PayPal, Credit/Debit Card, Venmo, Pay Later).
* Easy one-click account connection for PayPal Checkout using the automated onboarding flow.
* PayPal buttons on both the checkout page and the cart page.
* Compatible with the WooCommerce block-based checkout page.
* Compatible with the legacy shortcode-based checkout page.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).
* Full sandbox (test) mode support for both gateways.

**Configuration**

To configure the gateways, go to:

WooCommerce Settings -> Payments

You will see both "PayPal Pro" and "PayPal Checkout" listed as available payment methods. Click on each one to configure it.

* For PayPal Pro setup instructions, see the [PayPal Pro documentation](https://wp-ecommerce.net/paypal-pro-payment-gateway-for-woocommerce) page.
* For PayPal Checkout setup instructions, see the [PayPal Checkout documentation](https://wp-ecommerce.net/woocommerce-paypal-checkout-paypal-pro) page.

Post a question on the forum if you have any issue using the addon.

== Installation ==

Do the following to install the addon:

1. Upload the 'woocommerce-paypal-pro.zip' file from the Plugins->Add New page in the WordPress administration panel.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= What payment options does this plugin offer? =
This plugin offers two payment gateways: (1) PayPal Pro for on-site credit card checkout, and (2) PayPal Checkout for the latest PayPal payment buttons (PayPal, Credit/Debit Card, Venmo, Pay Later, etc.).

= Can I use both PayPal Pro and PayPal Checkout at the same time? =
Yes. Both payment methods are registered as separate gateways in WooCommerce. You can enable one or both depending on your needs.

= Will my customers be able to checkout using their credit cards after I install this plugin? =
Yes. With PayPal Pro enabled, customers can enter their credit card details directly on your checkout page. With PayPal Checkout, customers also have the option to pay with a credit/debit card through PayPal.

= Will PayPal Pro offer on-site checkout so my customers don't have to leave the site? =
Yes. PayPal Pro processes the credit card payment on your site. The customer never leaves your checkout page.

= Do I need a PayPal Business account? =
Yes. A PayPal Business account is required to accept payments via either gateway.

= Does it work with the legacy shortcode checkout page of WooCommerce? =
Yes. Both gateways work with the legacy shortcode-based checkout page.

= Does it work with the new block based checkout page of WooCommerce? =
Yes. Both gateways are compatible with the new WooCommerce block-based checkout page.

= Is it compatible with WooCommerce HPOS? =
Yes. This plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS).

= How do I configure the PayPal Checkout gateway? =
Go to WooCommerce > Settings > Payments > PayPal Checkout. Enable the gateway, then use the API Connection tab to connect your PayPal account with one click. See the [PayPal Checkout documentation](https://wp-ecommerce.net/woocommerce-paypal-checkout-paypal-pro) for detailed instructions.

== Screenshots ==

Please visit the following documentation pages to view screenshots:

* [PayPal Pro setup and configuration](https://wp-ecommerce.net/paypal-pro-payment-gateway-for-woocommerce)
* [PayPal Checkout setup and configuration](https://wp-ecommerce.net/woocommerce-paypal-checkout-paypal-pro)

== Changelog ==

= WIP =
- Description updated to include PayPal checkout features and details.
- Subscription feature added for PayPal PPCP.

= 4.0.0 =
- PayPal Commerce Platform (PPCP) payment option added. [PayPal Checkout setup and configuration documentation](https://wp-ecommerce.net/woocommerce-paypal-checkout-paypal-pro)
- Fixed an issue with the PayPal API error response handling where the error response was not being properly handled and displayed to the user.
- PayPal checkout onboarding feature added.
- PayPal checkout code updated.
- Payment Method icons updated.

= 3.0.3 =
- Display name updated.

= 3.0.2 =
- Introduced a new settings option to automatically add a unique prefix to the invoice number sent to the PayPal API. Thank you to @marketing1on1com for suggesting this.

= 3.0.1 =
- Improved code formatting for better readability.
- Enhanced compatibility with older PHP versions through minor adjustments.

= 3.0 = 
- WooCommerce Checkout Block support added.
- The addon now works with both the legacy shortcode checkout page and the new block checkout page.

= 2.9.16 =
- Updated the tags to limit it to 5. 

= 2.9.15 =
- Added HPOS feature compatibility.

= 2.9.14 =
- Removed the admin notice for the secure checkout option which has been removed from WooCommerce.

= 2.9.13 =
- Works on WooCommerce latest (v8.3.1) and WordPress latest (v6.4).

= 2.9.12 =
- PHP 8.2 compatibility.
- Added a method description for the settings menu.

= 2.9.11 =
- Updated the secure checkout admin notice message to include a link to the advanced settings menu so it is easy to find.
- Added a new filter hook that will allow the transaction type to be customized to "authorization" instead of "sale/capture". The filter hook is: wcpprog_request_query_arg_paymentaction

= 2.9.10 =
- Added a new filter hook "wcpprog_invnum_woo_order_number". It allows customization of the invoice number field.

= 2.9.9 =
- Reversing the temporary fix that was added for the PayPal's "Duplicate invoice ID supplied" error.

= 2.9.8 =
- Temporarily Ignore the "Duplicate invoice ID supplied" error. Thanks to @thaissamendes for sharing the code tweak.
- Added a new action hook "wcpprog_paypal_api_error_response" when the API response is an error response.

= 2.9.7 =
- Added a new filter hook (wcpprog_get_user_ip) for the IP address that gets submitted to the PayPal API. This hook can be used to override and apply your own customization for the IP Address.

= 2.9.6 =
- The "wcpprog_request_txn_description" filter can be used to override and customize the Transaction Description value.
- The description field of the API is now populated with a value like the following:
  WooCommerce Order ID: XXXX
- The last 4 digits of the card is saved in the order post meta (for the transaction).

= 2.9.5 =
- Added the email address value in the query parameter of the API. The billing email address will now be sent to the PayPal API.

= 2.9.4 =
- Removed (commented out) the individual item amount passing to the PayPal API (this was recently added after a request from a user). A few sites are having issues with it when dynamic pricing is used. It will still pass the item name.

= 2.9.3 =
- Variable product checkout error fixed. The dynamic pricing was causing an error with the additional amount parameter that the plugin now sends to PayPal.

= 2.9.2 =
- Added additional item info to the request parameter that is sent to PayPal. It will show more info about the item when you view the transaction in your PayPal account. Thanks to @kingpg

= 2.9.1 =
- Added a credit card icon next to the paypal checkout selection radio button.
- Added a filter (wcpprog_checkout_icon) to allow updating/tweaking of the credit card icon image.

= 2.9 =
- Updated a call to a function for the new WooCommerce version.

= 2.8 =
- Fixed WooCommerce settings URL in force SSL notice for the new version of WooCommerce.

= 2.7 =
- The shipping address is also sent to the PayPal API during the checkout.

= 2.6 =
- Added language translation POT file so the addon can be translated.

= 2.5 =
- The WooCommerce order number gets sent to the PayPal Pro API (so it is available in your merchant account)

= 2.4 =
- Credit Card number and CVV fields now show a placeholder text (the same as the field label).
- Added filters so the placeholder text can be customized using custom code.
- Added a filter that can be used to fully customize the output of the credit card fields on the checkout form.

= 2.3 =
- The credit card expiry year value has been increased.

= 2.2 =
- Added a class_exists check to make sure the 'WC_Payment_Gateway' class is available before trying to use it in the code.
- Added an empty index file in the plugin folder so it cannot be browsed.

= 2.1 =
- Replaced the "get_option('woocommerce_currency')" function call with get_woocommerce_currency()

= 2.0 =
- Plugin translation related improvements.
- Tested on WordPress 4.6 so it is compatible.

= 1.9 =
- Added translation string for the credit card form.

= 1.8 =
- Fix: settings interface not showing. Updated the addon to be compatible with the latest version of WooCommerce.
- Note: Make sure to go to the settings interface of this addon and save your API details.
- Added a link to the settings menu in the plugins listing interface.

= 1.7 =
- The credit card number will stay in the form when there is a validation error.

= 1.6 =
- Added a new filter so the CVV hint image can be customized.
- WP4.4 compatibility.

= 1.5 =
- Added a new option to show a hint for the credit card security code (verification number) on the checkout form.

= 1.4 =
- Some more code refactoring changes.

= 1.3 =
- Minor code refactoring to make it more readable.

= 1.1 =
- First commit to WordPress repository.

== Upgrade Notice ==
Make sure to go to the settings interface of this addon after the upgrade and save your API details (if the API details are missing).

== Arbitrary section ==
None
