/* Stock-only adapter for the standard WordPress inline editor and its existing AJAX request. */
(function ($, inlineEditPost, wp) {
    'use strict';
    const originalEdit = inlineEditPost.edit;
    const unknown = () => wp.i18n.__('Stock is unavailable. Reload the page or request technical review.', 'cpt2collection');
    const failure = () => wp.i18n.__('The stock save could not be confirmed. Reload the page and verify stock before making another change.', 'cpt2collection');
    function populate(id, value) {
        const editor = document.getElementById('edit-' + id);
        if (!editor) return;
        const input = editor.querySelector('[name="cpt2collection_quick_stock"]');
        const expected = editor.querySelector('[name="cpt2collection_quick_expected"]');
        if (!input || !expected) return;
        const valid = /^(0|[1-9][0-9]*)$/.test(value);
        input.value = valid ? value : '';
        input.disabled = !valid;
        expected.value = valid ? value : '';
        editor.querySelector('.cpt2collection-quick-stock-help').textContent = valid ? '' : unknown();
    }
    inlineEditPost.edit = function (id) {
        const result = originalEdit.apply(this, arguments);
        const postId = typeof id === 'object' ? this.getId(id) : id;
        const row = document.getElementById('post-' + postId);
        const stock = row && row.querySelector('.cpt2collection-stock-value');
        populate(postId, stock ? stock.getAttribute('data-stock') : '');
        return result;
    };
    $(document).ajaxComplete(function (event, xhr, settings) {
        const data = new URLSearchParams(settings.data || '');
        if (data.get('action') !== 'inline-save' || !data.has('cpt2collection_quick_stock')) return;
        const id = data.get('post_ID');
        if (!/^[1-9][0-9]*$/.test(id || '')) return;
        const editor = document.getElementById('edit-' + id);
        if (!editor || !editor.querySelector('[name="cpt2collection_quick_stock"]')) return;
        // On success core already replaced the row (including its fresh baseline) and removed the editor.
        const parsed = new DOMParser().parseFromString(xhr.responseText || '', 'text/html');
        const error = parsed.querySelector('.cpt2collection-quick-stock-error');
        const value = error ? error.getAttribute('data-stock') : '';
        const row = document.getElementById('post-' + id);
        const stock = row && row.querySelector('.cpt2collection-stock-value');
        if (stock) {
            stock.setAttribute('data-stock', value);
            stock.textContent = value || unknown();
        }
        // Discard the rejected target. A subsequent deliberate edit starts from displayed persistence.
        // Unknown outcomes leave no editable stock/baseline; there is never an automatic retry.
        populate(id, value);
        if (!error) {
            const notice = editor.querySelector('.inline-edit-save .notice-error');
            if (notice) {
                notice.classList.remove('hidden');
                notice.querySelector('.error').textContent = failure();
            }
            $( 'table.widefat .spinner' ).removeClass('is-active');
        }
    });
})(jQuery, inlineEditPost, window.wp);
