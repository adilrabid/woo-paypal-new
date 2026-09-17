# TASK
Need to add support for configuring subscription type products and checkout them using PayPal PPCP gateway.

# About
This plugin (Payment Gateway for PayPal Pro & PayPal Checkout for WooCommerce) is a addon for WooCommerce plugin whose purpose is to provide support for paypal pro and paypal ppcp gateway checkout. These gateways was originally added for one-time product checkout.
But now we want to add an option for configuring subscription type product and those product can be checkout using paypal ppcp.

## What needs to be added
Here is the short lists on what to do:
- Add subscription product configuring interface in edit product page.
- Add webhook configuration settings ui in a dedicated tab inside paypal ppcp settings page.
- Add support for subscription type product in paypal ppcp only (not for paypal pro).
- Add custom add to cart button level (maybe 'Subscribe').
- Custom price tag format (subscription plan details) both in the product display and cart page.
- The cart should not contain other product type with this custom subscription product type. So when a subscription product gets added to the cart, other existing products should be removed form cart. Similarly adding other product type to a cart, existing subscription type product will be removed from the cart.
- After the checkout is done, subscription transaction record will be in the 'Subscription' menu as well as in the orders menu lined with the subscription menu's record.
- Recurring payments will be captured via webhook and add necessary records on order/subscription menu.
- A Subscription cancel link/button is needed. Can be placed in the subscription transaction record details page's meta box.
- The checkout gateway support of paypal ppcp should work for both wc gutenberg block systems as well as for legacy wc shortcodes.

## Helpful pointers
- Custom subscription type product slug: wcpprog_subscription
- Custom subscription type product fields:
  - subscription_recurring_price
  - subscription_recurring_sale_price
  - subscription_recurring_billing_interval
  - subscription_recurring_billing_interval_type
  - subscription_reattempt_on_failure
  - subscription_recurring_billing_count
  - subscription_trial_period
  - subscription_trial_period_type
  - subscription_trial_price
- Subscription order type slug: wcpprog_sub_order

## Work done so far
Here is the list of things I've done so far. Some of then is done and some could be incomplete.

- Webhook configuring interface added and functional.
- A subscription type product is registered and configurable. Fields for subscription product related fields were also added and the product renders in the front end.
- Subscription product type is addable to the cart and checkout with ppcp for subscription product in the cart is possible via classic wc checkout shortcode (need to add support for wc gutenberg block as well).
- Transaction records gets added to the order/subscription menu (need to adjust displayed subscription related information).

## What to do next:
- Need to check and verify the existing added codes.
- Handle the recurring payments webhook events and update subscription transaction records.
- Handle subscription cancellation.

## Important notes:
- The lib/paypal folder contains our custom made paypal lib, the paypal related operation codes are there, so the structure might not need to be changed, but the methods can be adjusted. There could be codes for eStore (our another plugin) plugin, because we've copied the custom lib from that plugin previously.
- This subscription related feature is inspired by WooCommer's official Subscription addon. So when working, you can take knowledge from that as well.
- The existing one-time checkout related feature should not be affected.
- Do do any git commit.