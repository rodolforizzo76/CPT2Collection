/* Browser run authorization is ephemeral. The reset API owns all destructive authority. */
(function () {
    'use strict';
    const root = document.getElementById('c2c-reset');
    if (!root) return;
    const { __ } = wp.i18n;
    const config = JSON.parse(root.dataset.config);
    const node = name => document.getElementById('c2c-reset-' + name);
    const phrase = 'FULL COMMERCE RESET';
    const identityValid = value => typeof value === 'string' && /^[0-9a-f]{32}$/.test(value);
    const compare = (a, b) => a.length - b.length || (a > b ? 1 : a < b ? -1 : 0);
    const integerValid = value => typeof value === 'string' && /^(0|[1-9][0-9]*)$/.test(value) && compare(value, config.max_integer) <= 0;
    const phases = {
        validating: __('Checking commerce records', 'cpt2collection'),
        retiring_claims: __('Preserving payment history', 'cpt2collection'),
        terminalizing_events: __('Preserving payment history', 'cpt2collection'),
        deleting: __('Removing commerce records', 'cpt2collection'),
        finalizing: __('Finishing reset', 'cpt2collection')
    };
    const statuses = {
        running: __('Running', 'cpt2collection'),
        blocked: __('Blocked', 'cpt2collection'),
        completed: __('Completed', 'cpt2collection')
    };
    const messages = {
        ready: __('Reset ready', 'cpt2collection'),
        unavailable: __('A ready commerce context could not be verified. Look up an existing operation in Advanced details.', 'cpt2collection'),
        running: __('Reset in progress', 'cpt2collection'),
        checking: __('Checking the latest status', 'cpt2collection'),
        paused: __('Reset paused', 'cpt2collection'),
        unchanged: __('Reset paused. The last step could not be confirmed. Select Continue reset to check again and resume.', 'cpt2collection'),
        missing: __('Reset paused. No reset was found at this recovery URL.', 'cpt2collection'),
        retry: __('Reset paused. No reset was found. You can confirm the same start again.', 'cpt2collection'),
        failed: __('Reset paused. Check status again in Advanced details.', 'cpt2collection'),
        storage: __('Reset paused. Recovery storage is unavailable. Copy the recovery URL and reopen it when storage is available.', 'cpt2collection'),
        auth: __('Sign in again to continue. Reopen the recovery URL after signing in.', 'cpt2collection'),
        blocked: __('Reset blocked. Investigate the operation in Advanced details.', 'cpt2collection'),
        completed: __('Reset complete. Commerce is available again.', 'cpt2collection'),
        historical: __('Reset complete. Check current commerce status separately.', 'cpt2collection'),
        invalid: __('Enter a valid operation identity in Advanced details.', 'cpt2collection')
    };
    let identity = '', source = null, revision = null, snapshot = null;
    let journal = null, state = 'ready', busy = false, run = false, pause = false;
    let generation = 0, restored = false, authStopped = false, storageFailed = false;
    let observedRunning = false;

    function render() {
        const mayStart = !busy && !authStopped && !storageFailed && identityValid(config.source) &&
            (state === 'ready' || state === 'retry' && journal && journal.source === config.source);
        node('start-form').hidden = !mayStart;
        node('start-confirmation').disabled = !mayStart;
        node('start').disabled = !mayStart || node('start-confirmation').value !== phrase;
        node('start').textContent = state === 'retry' ? __('Retry same start', 'cpt2collection') : __('Start reset', 'cpt2collection');
        node('resume').disabled = node('resume-control').hidden = busy || authStopped || storageFailed || !snapshot?.can_resume ||
            !['paused', 'unchanged'].includes(state);
        node('pause-control').hidden = !run;
        node('pause').disabled = !run || pause;
        node('progress').hidden = !run;
        node('identity').disabled = busy;
        node('inspect').disabled = busy || !identityValid(node('identity').value);
        node('orders-control').hidden = state !== 'completed';
        node('recovery-wrap').hidden = !identity;
        const result = node('result');
        const message = messages[state === 'completed' && !observedRunning && !identityValid(config.source) ? 'historical' : state];
        if (result.querySelector('p').textContent !== message) result.querySelector('p').textContent = message;
        result.className = 'notice inline ' + (state === 'completed' ? 'notice-success' :
            ['ready', 'checking', 'running', 'paused'].includes(state) ? 'notice-info' : 'notice-warning');
        root.dataset.state = state;
    }

    function recoveryUrl(id) {
        const url = new URL(config.page_url);
        url.searchParams.set('operation', id);
        return url.href;
    }
    const journalKey = id => config.storage_key + ':' + id;
    const lastKey = config.storage_key + ':last';
    function readJournal(id) {
        const raw = sessionStorage.getItem(journalKey(id));
        // The previous UI kept one installation/user-scoped record in localStorage.
        const record = raw === null ? JSON.parse(localStorage.getItem(config.storage_key)) : JSON.parse(raw);
        if (!record || raw === null && record.identity !== id) return null;
        if (record.version !== 1 || record.identity !== id || !identityValid(record.source) ||
            ![null, 'start', 'resume'].includes(record.pending) ||
            !(record.revision === null || integerValid(record.revision)) ||
            record.pending === 'resume' && record.revision === null) throw Error('Invalid recovery record');
        return record;
    }
    function persist(pending) {
        const record = { version: 1, identity, source, pending, revision };
        const raw = JSON.stringify(record);
        sessionStorage.setItem(journalKey(identity), raw);
        if (pending) sessionStorage.setItem(lastKey, identity);
        if (sessionStorage.getItem(journalKey(identity)) !== raw ||
            pending && sessionStorage.getItem(lastKey) !== identity) throw Error('Recovery storage unavailable');
        journal = record;
    }
    function select(id) {
        const url = recoveryUrl(id);
        window.history.replaceState(null, '', url);
        if (identity !== id) {
            identity = id;
            observedRunning = false;
            source = revision = snapshot = journal = null;
            node('operation').hidden = true;
            node('phase').textContent = '';
            try {
                journal = readJournal(id);
                if (journal) ({ source, revision } = journal);
            } catch (error) { storageFailed = true; }
        }
        node('identity').value = id;
        node('recovery').value = url;
        node('start-confirmation').value = '';
    }

    function display(op) {
        for (const [field, value] of Object.entries({
            identity: op.operation_identity, source: op.source_epoch_identity, phase: op.phase,
            revision: op.revision, checkpoint: op.checkpoint ?? '—', cursor: op.cursor, status: statuses[op.status]
        })) node('value-' + field).textContent = value;
        node('phase').textContent = op.status !== 'running' ? '' :
            op.phase === 'deleting' && op.checkpoint === 'z1_n_0_done' ? phases.finalizing : phases[op.phase];
        node('operation').hidden = false;
    }
    function validOperation(response, data, action) {
        const op = data?.operation;
        if (!op || data.verify_state !== false || op.operation_identity !== identity ||
            !identityValid(op.source_epoch_identity) || source && source !== op.source_epoch_identity ||
            !integerValid(op.revision) || !integerValid(op.cursor) ||
            !(op.checkpoint === null || typeof op.checkpoint === 'string' && /^[a-z][a-z0-9_]{0,63}$/.test(op.checkpoint))) return false;
        const completed = op.status === 'completed', blocked = op.status === 'blocked';
        if (!['running', 'blocked', 'completed'].includes(op.status) ||
            op.completed !== completed || op.blocked !== blocked ||
            op.can_resume !== (op.status === 'running' && op.phase !== 'finalizing') ||
            (completed ? op.phase !== 'completed' || op.checkpoint !== 'z1_n_0_done' || op.cursor !== '0' : !Object.hasOwn(phases, op.phase)) ||
            data.success !== !blocked ||
            data.next_action !== (blocked ? 'investigate' : completed ? 'none' : 'query_status')) return false;
        if (blocked) return response.status === 409 && data.code === 'blocked';
        if (completed) return response.status === 200 && (data.code === 'completed' || action === 'start' && data.code === 'existing');
        if (action === 'start' && data.code === 'existing') return response.status === 200;
        return response.status === 202 && data.code === ({ status: 'running', start: 'started', resume: 'progressed' })[action];
    }
    function consistent(op, previous) {
        if (revision !== null && compare(op.revision, revision) < 0) return false;
        if (!previous) return true;
        if (compare(op.revision, previous.revision) < 0 ||
            previous.status !== 'running' && previous.revision !== op.revision) return false;
        return previous.revision !== op.revision || ['status', 'phase', 'checkpoint', 'cursor', 'can_resume']
            .every(field => op[field] === previous[field]);
    }

    async function request(url, method, body) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(url, { method, credentials: 'same-origin', cache: 'no-store',
                redirect: 'error', signal: controller.signal,
                headers: body ? { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' } : { 'X-WP-Nonce': config.nonce },
                ...(body ? { body: JSON.stringify(body) } : {}) });
            if ([401, 403].includes(response.status)) return [response, null];
            return [response, await response.json()];
        } finally { clearTimeout(timer); }
    }

    async function inspect(token, acknowledgement) {
        const previous = acknowledgement || snapshot;
        try {
            const [response, data] = await request(config.status_url.replace('__operation__', identity), 'GET');
            if (token !== generation) return 'failed';
            if ([401, 403].includes(response.status)) authStopped = true;
            if (authStopped) return 'auth';
            if (validOperation(response, data, 'status')) {
                const op = data.operation;
                if (!consistent(op, previous)) return 'blocked';
                snapshot = op;
                source = op.source_epoch_identity;
                revision = op.revision;
                display(op);
                if (op.status === 'running') observedRunning = true;
                // Keep the exact revision floor on reload. Equal uncertain requests stay pending.
                if (journal && (journal.revision === null || compare(revision, journal.revision) > 0)) {
                    try { persist(null); } catch (error) { storageFailed = true; }
                }
                if (op.completed) {
                    try {
                        if (sessionStorage.getItem(lastKey) === identity) sessionStorage.removeItem(lastKey);
                    } catch (error) { /* Exact completion is independent of browser storage. */ }
                    return 'completed';
                }
                if (!op.can_resume) return 'blocked';
                return storageFailed ? 'storage' : 'paused';
            }
            if (response.status === 404 && data?.code === 'not_found' && data.verify_state === false &&
                data.success === false && data.operation === null && data.next_action === 'query_status') {
                if (revision !== null || previous) return 'blocked';
                return journal?.pending === 'start' && source === config.source && !storageFailed ? 'retry' : 'missing';
            }
            return data?.operation || ['blocked', 'inconsistent_state', 'epoch_id_exhausted'].includes(data?.code) ? 'blocked' : 'failed';
        } catch (error) { return 'failed'; }
    }

    async function mutate(action, token) {
        const sent = revision;
        try { persist(action); }
        catch (error) { storageFailed = true; return 'storage'; }
        const isStart = action === 'start';
        const body = isStart ? { operation_identity: identity, expected_epoch_identity: source, confirmation: phrase } :
            { expected_epoch_identity: source, revision: sent, confirmation: phrase + ' ' + identity };
        let acknowledgement, halt;
        try {
            const [response, data] = await request(isStart ? config.start_url : config.resume_url.replace('__operation__', identity), 'POST', body);
            if (token !== generation) return 'paused';
            if ([401, 403].includes(response.status)) authStopped = true;
            else if (validOperation(response, data, action)) {
                if (consistent(data.operation, snapshot)) {
                    acknowledgement = data.operation;
                    revision = acknowledgement.revision;
                } else halt = 'blocked';
            } else if (data?.operation || ['blocked', 'inconsistent_state', 'epoch_id_exhausted', 'conflict'].includes(data?.code)) halt = 'blocked';
            else if (!(response.status === 503 && data?.verify_state === true &&
                ['verification_required', 'transaction_outcome_unknown'].includes(data.code))) halt = 'failed';
        } catch (error) { /* HTTP loss leaves the outcome to exact inspection. */ }
        if (token !== generation) return 'paused';
        const checked = await inspect(token, acknowledgement);
        if (halt === 'blocked' && checked !== 'auth') return 'blocked';
        if (checked !== 'paused') return checked;
        if (halt) return halt;
        // Equal after a POST grants no automatic replay, even if the server reply was lost.
        return sent !== null && compare(revision, sent) === 0 ? 'unchanged' : 'paused';
    }

    async function work(mode, id) {
        if (busy || (mode !== 'status' && authStopped)) return;
        busy = true;
        const token = ++generation;
        try {
            select(id);
            run = mode !== 'status';
            pause = false;
            state = run ? 'running' : 'checking';
            render();
            if (run) node('result').focus();
            if (mode === 'start' && storageFailed) { state = 'storage'; return; }
            if (mode === 'start' && !source) source = config.source;
            let next = mode === 'start' ? await mutate('start', token) : await inspect(token);
            while (token === generation && run && !pause && next === 'paused') {
                next = await mutate('resume', token);
            }
            if (token === generation) state = next;
        } catch (error) {
            if (token === generation) state = 'failed';
        } finally {
            run = busy = false;
            render();
            if (restored) {
                restored = false;
                if (identity) void work('status', identity);
            }
        }
    }

    node('start-form').addEventListener('submit', event => {
        event.preventDefault();
        if (node('start').disabled || node('start-confirmation').value !== phrase || busy) return;
        try {
            const id = identity || Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
            void work('start', id);
        } catch (error) { state = 'failed'; render(); }
    });
    node('resume').addEventListener('click', () => {
        if (!node('resume').disabled) void work('resume', identity);
    });
    node('pause').addEventListener('click', () => {
        pause = true;
        state = 'checking';
        render();
        node('result').focus();
    });
    node('inspect-form').addEventListener('submit', event => {
        event.preventDefault();
        if (identityValid(node('identity').value)) void work('status', node('identity').value);
    });
    for (const name of ['start-confirmation', 'identity']) node(name).addEventListener('input', render);
    window.addEventListener('pagehide', () => { ++generation; run = false; });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        node('start-confirmation').value = '';
        if (busy) restored = true;
        else if (identity) void work('status', identity);
        else render();
    });
    let initial = config.identity;
    try {
        if (!initial && !config.invalid_identity) initial = sessionStorage.getItem(lastKey);
    } catch (error) { storageFailed = true; }
    if (identityValid(initial)) void work('status', initial);
    else {
        state = config.invalid_identity ? 'invalid' : storageFailed ? 'storage' : identityValid(config.source) ? 'ready' : 'unavailable';
        render();
    }
})();
