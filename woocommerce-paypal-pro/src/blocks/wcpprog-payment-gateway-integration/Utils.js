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

export function getAttributionData() {
    return typeof window.wc_order_attribution !== 'undefined' ? window.wc_order_attribution.getAttributionData() : {};
}

const getResponse = async ( response ) => {
    const data = await response.json();

    if ( ! response.ok || ! data.success ) {
        throw new Error( data?.data?.message || 'PayPal checkout could not be completed.' );
    }

    return data.data;
};

export async function ajaxPost(values) {
    const config = getPayPalPPCPSettings('ajax', {});
    const formData = new FormData();

    Object.entries(values).forEach(([key, value]) => formData.append(key, value));

    formData.append('attributions', JSON.stringify(getAttributionData()));

    return getResponse(await fetch(config.url, {
        method: 'POST',
        body: formData}
    ));
}