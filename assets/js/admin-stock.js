/* Keep the block editor's background metabox save feedback and stock baseline current. */
(function (wp) {
    'use strict';
    wp.apiFetch.use(async function (options, next) {
        if (!window._wpMetaBoxUrl || options.url !== window._wpMetaBoxUrl || options.method !== 'POST') {
            return next(options);
        }
        const quantity = document.querySelector('[name="cpt2collection_stock_quantity"]');
        const expected = document.querySelector('[name="cpt2collection_stock_expected"]');
        if (!quantity || !expected) return next(options);
        const submitted = quantity.value;
        const notices = wp.data.dispatch('core/notices');
        const noticeId = 'cpt2collection-stock-save';
        let stockMessage = '';
        try {
            const response = await next(options);
            // Core deliberately requests the redirected editor HTML with parse:false.
            const saved = new DOMParser().parseFromString(await response.clone().text(), 'text/html');
            const error = saved.querySelector('#cpt2collection-stock-error');
            if (error) {
                stockMessage = error.textContent.trim();
                throw new Error();
            }
            const baseline = saved.querySelector('[name="cpt2collection_stock_expected"]');
            if (!baseline || !/^(0|[1-9][0-9]*)$/.test(baseline.value)) throw new Error();
            // Preserve typing during the request; its next save still uses the new baseline.
            if (quantity.value === submitted) quantity.value = baseline.value;
            expected.value = baseline.value;
            notices.removeNotice(noticeId);
            return response;
        } catch (error) {
            const message = stockMessage || wp.i18n.__(
                'The stock save could not be confirmed. Reload and verify the current stock before making another change.',
                'cpt2collection'
            );
            notices.createErrorNotice(message, { id: noticeId, isDismissible: false });
            // Let WordPress mark the metabox update as failed as well. Never resend it here.
            throw error;
        }
    });
})(window.wp);
