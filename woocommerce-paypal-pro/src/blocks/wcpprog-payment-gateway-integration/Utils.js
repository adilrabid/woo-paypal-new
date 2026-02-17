import { getSetting } from '@woocommerce/settings';

export function getSettings(key, settingsGroup, defaultValue = null){
    const settings = getSetting( settingsGroup, {} );
    return settings[key] || defaultValue;
}

export function getPayPalProSettings(key, defaultValue = null){
    return getSettings(key, "paypalpro_data", defaultValue);
}

export function getPayPalPPCPSettings(key, defaultValue = null){
    return getSettings(key, "paypal_checkout_data", defaultValue);
}