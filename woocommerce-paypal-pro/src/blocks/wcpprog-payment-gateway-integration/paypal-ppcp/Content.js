import {decodeEntities} from '@wordpress/html-entities';
import {select, dispatch} from '@wordpress/data';
import {validationStore} from '@woocommerce/block-data';
import {store as noticesStore} from '@wordpress/notices';
import {useEffect, useRef} from 'react';
import {getPayPalPPCPSettings, ajaxPost} from '../Utils';

/** Render the PayPal SDK button for the Checkout block. */
export const PayPalButton = ({disabled = false, waitingForProcessing = false, isEditor = false}) => {
    const container = useRef(null);
    const sdkActions = useRef(null);
    const busy = useRef(false);
    const processing = useRef(false);
    processing.current = disabled || waitingForProcessing;
    const checkoutType = getPayPalPPCPSettings('checkoutType', 'capture');
    const whMissingNotice = getPayPalPPCPSettings( 'webhook_missing_notice', '');

    useEffect(() => {
        if (sdkActions.current) {
            if (processing.current || busy.current) {
                sdkActions.current.disable();
            } else {
                sdkActions.current.enable();
            }
        }
    }, [disabled, waitingForProcessing]);

    useEffect(() => {
        if (isEditor) {
            return undefined;
        }

        if (!container.current || !window.paypal) {
            // The PHP integration declares the PayPal SDK as a script dependency.
            console.error('PayPal SDK was not loaded for Checkout block.');
            return undefined;
        }

        const config = getPayPalPPCPSettings('ajax', {});
        let disposed = false;
        const setBusy = (value) => {
            if (disposed) {
                return;
            }
            busy.current = value;
            if (sdkActions.current) {
                if (value || processing.current) {
                    sdkActions.current.disable();
                } else {
                    sdkActions.current.enable();
                }
            }
        };
        const showError = (error) => {
            if (disposed) {
                return;
            }
            setBusy(false);
            console.error('PayPal Checkout error:', error);
            dispatch(noticesStore).createErrorNotice(error.message || 'An error occurred during payment. Please try again.', {
                id: 'wcpprog-paypal-error',
                context: 'wc/checkout',
                isDismissible: true,
            });
        };
        const buttonConfig = {
            onInit: (data, actions) => {
                if (!disposed) {
                    sdkActions.current = actions;
                    setBusy(busy.current);
                }
            },
            onClick: (data, actions) => {
                if (disposed || processing.current || busy.current) {
                    return actions.reject();
                }

                dispatch(noticesStore).removeNotice('wcpprog-paypal-error', 'wc/checkout');

                dispatch(validationStore).showAllValidationErrors();
                if (select(validationStore).hasValidationErrors()) {
                    // Allow React to render the inline errors before focusing.
                    window.requestAnimationFrame(() => {
                        if (!disposed) {
                            const field = document.querySelector('.wc-block-checkout [aria-invalid="true"]');
                            field?.focus();
                            field?.scrollIntoView({block: 'center', behavior: 'smooth'});
                        }
                    });
                    return actions.reject();
                }

                return actions.resolve();
            },
            onCancel: () => setBusy(false),
            onError: showError,
        };

        if ('subscription' === checkoutType) {
            buttonConfig.createSubscription = async () => {
                setBusy(true);
                try {
                    const customer = select('wc/store/cart')?.getCustomerData();
                    const address = customer?.shippingAddress;
                    const shippingName = [address?.first_name, address?.last_name].filter(Boolean).join(' ').trim();
                    const result = await ajaxPost({
                        action: config.createSubscriptionAction,
                        nonce: config.nonce,
                        shipping_full_name: shippingName,
                        checkout_customer: JSON.stringify({
                            billing: customer?.billingAddress,
                            shipping: customer?.shippingAddress
                        })
                    });
                    return result.subscription_id;
                } catch (error) {
                    showError(error);
                    throw error;
                }
            };
            buttonConfig.onApprove = async (data, actions) => {
                setBusy(true);
                try {
                    const transaction = await actions.subscription.get();
                    const result = await ajaxPost({
                        action: config.approveSubscriptionAction,
                        nonce: config.nonce,
                        data: JSON.stringify(data),
                        txn_data: JSON.stringify(transaction),
                    });
                    window.location.assign(result.redirect_to);
                } catch (error) {
                    showError(error);
                }
            };
        } else {
            buttonConfig.createOrder = async () => {
                setBusy(true);
                try {
                    const result = await ajaxPost({action: config.createOrderAction, nonce: config.nonce});
                    return result.order_id;
                } catch (error) {
                    showError(error);
                    throw error;
                }
            };
            buttonConfig.onApprove = async (data) => {
                setBusy(true);
                try {
                    const result = await ajaxPost({
                        action: config.captureOrderAction,
                        nonce: config.nonce,
                        paypal_order_id: data.orderID,
                    });
                    window.location.assign(result.redirect);
                } catch (error) {
                    showError(error);
                }
            };
        }

        const button = window.paypal.Buttons(buttonConfig);
        Promise.resolve(button.render(container.current)).catch(showError);

        return () => {
            disposed = true;
            sdkActions.current = null;
            busy.current = false;
            // Let the SDK dispose its iframe and listeners before removing them.
            Promise.resolve(button.close()).catch((error) => {
                console.error('PayPal button cleanup failed:', error);
            });
        };
    }, [checkoutType, isEditor]);

    if (isEditor) {
        return <div>{'PayPal Checkout button'}</div>;
    }

    if (whMissingNotice.trim().length){
        return <div style={{color: '#cc0000', width: '100%'}}>{whMissingNotice}</div>
    }

    return <div ref={container} style={{width: '100%'}}/>;
};

export default () => {
    const description = decodeEntities(getPayPalPPCPSettings('description', ''));

    return <p>{description}</p>;
};
