/* Deliberate, single-request adapter. Stored state is only a stop/receipt hint, never authority. */
(function () {
    'use strict';
    const root = document.getElementById('c2c-order-deletion');
    if (!root) return;
    const { __ } = wp.i18n;
    const config = JSON.parse(root.dataset.config);
    const reveal = document.getElementById('c2c-delete-reveal');
    const form = document.getElementById('c2c-delete-form');
    const input = document.getElementById('c2c-delete-confirmation');
    const submit = document.getElementById('c2c-delete-submit');
    const cancel = document.getElementById('c2c-delete-cancel');
    const notice = document.getElementById('c2c-delete-result');
    const phrase = 'DELETE ORDER C2C-' + config.order_id;
    let stopped = false;
    let busy = false;
    const messages = {
        deleted: __('The Order was permanently deleted.', 'cpt2collection'),
        already_deleted: __('The Order was already permanently deleted, confirmed by durable deletion evidence.', 'cpt2collection'),
        absence_unprovable: __('The Order is absent, but deletion cannot be proved. The outcome remains unverified.', 'cpt2collection'),
        already_absent: __('The Order is absent, but deletion cannot be proved. The outcome remains unverified.', 'cpt2collection'),
        evidence_conflict: __('Deletion was rejected because identity evidence conflicts. Investigation is required.', 'cpt2collection'),
        inconsistent_state: __('Deletion was rejected because stored state is inconsistent. Investigation is required.', 'cpt2collection'),
        invalid_order_id: __('Deletion was rejected: invalid Order reference.', 'cpt2collection'),
        invalid_request: __('Deletion was rejected: a valid confirmation and exact commerce context are required.', 'cpt2collection'),
        confirmation_required: __('Deletion was rejected: the exact confirmation is required.', 'cpt2collection'),
        stale_context: __('Deletion was rejected: the displayed commerce context is no longer current. Inspect the Order again.', 'cpt2collection'),
        database_error: __('Deletion was not confirmed because of a database failure. Inspect the Order before further action.', 'cpt2collection'),
        forbidden: __('Deletion was rejected: administrator permission is required.', 'cpt2collection'),
        authentication_required: __('Deletion was rejected: sign in again before inspecting the Order.', 'cpt2collection'),
        rest_cookie_invalid_nonce: __('Deletion was rejected: the security nonce is invalid or expired. Reload for read-only inspection.', 'cpt2collection')
    };
    const unknown = () => __('The deletion outcome remains unverified. Use read-only inspection. Order absence does not prove deletion. Do not repeat this DELETE.', 'cpt2collection');
    function show(message, success = false, focus = true) {
        notice.hidden = false;
        notice.className = 'notice inline ' + (success ? 'notice-success' : 'notice-warning');
        notice.querySelector('p').textContent = message;
        if (focus) notice.focus();
    }
    function stop() {
        stopped = true;
        if (reveal) { reveal.hidden = true; reveal.disabled = true; reveal.setAttribute('aria-expanded', 'false'); }
        if (form) { form.hidden = true; input.value = ''; submit.disabled = true; cancel.disabled = true; }
    }
    function persist(value) {
        localStorage.setItem(config.key, value);
        if (localStorage.getItem(config.key) !== value) throw Error('Stop marker unavailable');
    }
    function inspectMarker(focus = false) {
        try {
            const marker = localStorage.getItem(config.key);
            if (marker) {
                stop();
                // Receipts describe a previously confirmed response; they are not a new server proof.
                show((marker === 'deleted' || marker === 'already_deleted')
                    ? __('This browser previously received a confirmed deletion response. This read-only view does not independently verify that receipt.', 'cpt2collection')
                    : unknown(), false, focus);
            }
        } catch (error) {
            stop();
            show(__('Deletion is unavailable because browser recovery state cannot be read. Use read-only inspection.', 'cpt2collection'), false, focus);
        }
    }
    inspectMarker();
    window.addEventListener('pageshow', () => inspectMarker());
    window.addEventListener('storage', event => { if (event.key === config.key && event.newValue) inspectMarker(); });
    if (!form || config.read_only) return;
    if (!stopped) reveal.hidden = false;
    reveal.addEventListener('click', () => {
        if (stopped || busy) return;
        form.hidden = false;
        reveal.setAttribute('aria-expanded', 'true');
        input.focus();
    });
    input.addEventListener('input', () => { submit.disabled = stopped || busy || input.value !== phrase; });
    cancel.addEventListener('click', () => {
        if (stopped || busy) return;
        input.value = '';
        submit.disabled = true;
        form.hidden = true;
        reveal.setAttribute('aria-expanded', 'false');
        reveal.focus();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (stopped || busy || form.hidden || input.value !== phrase) return;
        // Recheck another tab's stop marker before dispatch. Server admission handles actual races.
        inspectMarker();
        if (stopped) return;
        try { persist('pending'); } catch (error) {
            stop();
            show(__('Deletion was not sent because recovery state could not be saved.', 'cpt2collection'));
            return;
        }
        busy = true;
        stop();
        show(__('Deletion request in progress. Leaving this page will require read-only inspection.', 'cpt2collection'));
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(config.url, {
                method: 'DELETE', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify({ confirmation: phrase, expected_context: config.expected_context })
            });
            const data = await response.json();
            // Permission failures use WordPress's own error envelope before the route callback.
            if (data && data.code === 'cpt2collection_order_delete_authentication_required') data.code = 'authentication_required';
            if (data && data.code === 'cpt2collection_order_delete_forbidden') data.code = 'forbidden';
            const confirmed = response.status === 200 && data.success === true && data.verify_state === false &&
                String(data.order_id) === config.order_id && (data.code === 'deleted' || data.code === 'already_deleted');
            if (confirmed) {
                persist(data.code);
                show(messages[data.code], true);
            } else if (data && data.verify_state !== true && data.success !== true && messages[data.code] &&
                ((response.status === 400 && ['invalid_order_id', 'invalid_request', 'confirmation_required'].includes(data.code)) ||
                 (response.status === 409 && ['absence_unprovable', 'already_absent', 'evidence_conflict', 'inconsistent_state', 'stale_context'].includes(data.code)) ||
                 (response.status === 500 && data.code === 'database_error') ||
                 (response.status === 401 && data.code === 'authentication_required') ||
                 (response.status === 403 && ['forbidden', 'rest_cookie_invalid_nonce'].includes(data.code)))) {
                localStorage.removeItem(config.key);
                show(messages[data.code]);
            } else {
                persist('unverified');
                show(unknown());
            }
        } catch (error) {
            // Includes response loss, malformed responses, timeout and receipt-storage failure. Never replay.
            show(unknown());
        } finally {
            clearTimeout(timeout);
            busy = false;
        }
    });
})();
