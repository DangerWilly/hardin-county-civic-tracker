/* The Hardin Civic — site.js
 *
 * Progressive enhancement only — the site MUST work with JS disabled. Every
 * form here is fully functional server-side; this file only adds polish:
 *
 *   1. Submit-button debounce: when a form submits, disable the button and
 *      change its label. Prevents accidental double-submits (which were
 *      creating duplicate meetings during admin entry).
 *
 *   2. Future home for: the AI ask box streaming client, anything else that
 *      genuinely needs JS.
 */
(() => {
    'use strict';

    /* ----- Form double-submit prevention ----- *
     * Any form with class .js-once disables its submit button on first
     * submit. The button keeps the form's underlying behavior intact (the
     * browser still submits) but the user can't click it again.
     *
     * Note we do NOT call preventDefault() — we want the normal POST to
     * happen. We just stop the button from being clickable AFTER the first
     * click has already started the submission.
     */
    document.querySelectorAll('form.js-once').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"]');
            if (!btn || btn.disabled) return;

            // Remember original text so we can put it back if the user
            // navigates back to this form via the back button.
            btn.dataset.originalText = btn.textContent;
            btn.textContent = btn.dataset.busyText || 'Working…';
            btn.disabled = true;

            // Safety net: if the request takes longer than 15 seconds,
            // re-enable so the user isn't stuck. Real failures should
            // also re-enable, but those land us back on the form anyway
            // (server re-renders with validation errors).
            setTimeout(() => {
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText;
            }, 15000);
        });
    });

    /* ----- Back-button button reset -----
     * On bfcache restore (browser back button restores a cached page), the
     * disabled state from the previous submit might persist. The pageshow
     * event with .persisted = true tells us we came from bfcache.
     */
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.querySelectorAll('form.js-once button[type="submit"]').forEach((btn) => {
            if (btn.dataset.originalText) {
                btn.textContent = btn.dataset.originalText;
            }
            btn.disabled = false;
        });
    });
})();
