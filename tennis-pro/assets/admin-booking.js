/* Tennis Pro – Admin Booking Page JS */
(function () {
    'use strict';

    const cfg  = window.TennisAdminBooking || {};
    const AJAX = cfg.ajaxUrl || '';
    const NONCE= cfg.nonce   || '';
    const i18n = cfg.i18n    || {};

    function post(action, data) {
        const body = new URLSearchParams({ action, nonce: NONCE, ...data });
        return fetch(AJAX, {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : body.toString(),
        }).then(r => r.json());
    }

    /* ── Cancel recurring series buttons ── */
    document.querySelectorAll('.tnp-cancel-series').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            if (!id) return;
            if (!confirm(i18n.confirmCancel || 'Serie stornieren?')) return;

            this.disabled = true;
            post('tennis_cancel_recurring', { recurring_id: id })
                .then(res => {
                    if (res.success) {
                        // Reload the page to reflect the cancellation
                        window.location.reload();
                    } else {
                        alert(res.data?.message || i18n.error || 'Fehler.');
                        this.disabled = false;
                    }
                })
                .catch(() => {
                    alert(i18n.error || 'Netzwerkfehler.');
                    this.disabled = false;
                });
        });
    });

})();
