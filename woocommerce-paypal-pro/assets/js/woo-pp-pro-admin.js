/* global wcpprog_admin_js_vars */

document.addEventListener('DOMContentLoaded', function(){
    const {actions, str, nonces, ajaxUrl, is_sandbox_enabled} = wcpprog_admin_js_vars;
    const mode = is_sandbox_enabled ? 'test' : 'live';

    async function check_webhooks() {
        const url = new URL(ajaxUrl);
        url.searchParams.append('action', actions.check_webhook);
        url.searchParams.append('_wpnonce', nonces.check_webhook);

        try {
            let response = await fetch(url, {
                method: 'GET',
            });

            const {data} = await response.json();

            // console.log(data); // Debug purpose only.

            display_webhook_status(mode, data);

        } catch (error) {
            console.log(error.message);
            display_webhook_status(mode, {
                'message': error.message,
            });
        }
    }

    async function create_webhooks(mode){
        const formData = new FormData();
        formData.append('action', actions.create_webhook);
        formData.append('_wpnonce', nonces.create_webhook);
        formData.append('mode', mode);

        try {
            let response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const {data} = await response.json();

            // console.log(data); // Debug purpose only.

            display_webhook_status(mode, data);

        } catch (error) {
            console.log(error.message);
            display_webhook_status(mode, {
                'message': error.message,
            });
        }
    }

    async function delete_webhooks(mode = ''){
        const formData = new FormData();
        formData.append('action', actions.delete_webhook);
        formData.append('_wpnonce', nonces.delete_webhook);

        try {
            let response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const {data} = await response.json();

            // console.log(data); // Debug purpose only.

            display_webhook_status_all_modes( data );

        } catch (error) {
            console.log(error.message);
            display_webhook_status_all_modes({
                'message': error.message,
            });
        }
    }

    function display_webhook_status_all_modes(resp_data){
        // console.log(resp_data); // Debug purpose only.
        Object.keys(resp_data).forEach((mode) => {
            if (['live', 'sandbox'].includes(mode)) {
                display_webhook_status(mode, resp_data[mode]);
            }
        })
    }

    function display_webhook_status(mode, data) {
        const createBtn = document.querySelector('button.wcpprog-paypal-ppcp-create-webhook-btn[data-hook-mode="'+mode+'"]');

        const resp_status = data.status || 'no';
        const resp_msg = data.msg || data.message;

        statusEl.innerHTML = ''; // Clear old msg

        const statusResIcon = document.createElement('span');
        statusResIcon.classList.add('dashicons', 'dashicons-' + resp_status);
        const statusResMsg = document.createElement('span');
        statusResMsg.classList.add('wcpprog-paypal-ppcp-webhook-statuc-msg');
        statusResMsg.textContent = resp_msg;

        statusEl.appendChild(statusResIcon);
        statusEl.appendChild(statusResMsg);

        if (data.hasOwnProperty('hidebtn')) {
            if (data.hidebtn) {
                createBtn.style.display = 'none';
            } else {
                createBtn.style.display = 'block';
            }
        }
    }

    const statusElSelector = '.wcpprog-paypal-ppcp-'+ mode +'-webhook-status';
    const statusEl = document.querySelector(statusElSelector);
    if (statusEl) {
        check_webhooks();
    }

    const createWebhookBtn = document.querySelector('.wcpprog-paypal-ppcp-create-webhook-btn');
    createWebhookBtn?.addEventListener('click', function(e){
        e.preventDefault();

        const btn = e.target;
        const mode = btn.dataset.hookMode;

        btn.disabled = true;

        create_webhooks(mode).then(() => {
            btn.disabled = false
        });
    });

    const deleteWebhookBtn = document.getElementById('wcpprog-paypal-ppcp-delete-webhook-btn');
    deleteWebhookBtn?.addEventListener('click', function(e){
        e.preventDefault();

        const btn = e.target;

        btn.disabled = true;

        delete_webhooks().then(() => {
            btn.disabled = false;
        });
    });
})