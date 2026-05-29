/* Tennis Pro v4.8 – Frontend JS
 * Config injected by wp_localize_script as window.TennisPro
 */
(function () {
    'use strict';

    const AJAX     = TennisPro.ajaxUrl;
    const NONCE    = TennisPro.nonce;
    const DATE     = TennisPro.date;
    const LOGGED   = TennisPro.loggedIn === '1';
    const IS_ADMIN = TennisPro.isAdmin  === '1';
    const i18n     = TennisPro.i18n;

    /* ── DOM refs ────────────────────────────────────────────────────── */
    const popup  = document.getElementById('tnp-popup');
    const panels = {
        guest : document.getElementById('tnp-panel-guest'),
        view  : document.getElementById('tnp-panel-view'),
        book  : document.getElementById('tnp-panel-book'),
        edit  : document.getElementById('tnp-panel-edit'),
    };

    /* ── State ───────────────────────────────────────────────────────── */
    let activeCell = null;

    /* ── Popup helpers ───────────────────────────────────────────────── */
    function showPopup(key) {
        Object.values(panels).forEach(p => { if(p) p.hidden = true; });
        if (panels[key]) panels[key].hidden = false;
        popup.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closePopup() {
        popup.hidden = true;
        document.body.style.overflow = '';
        activeCell = null;
    }

    function setError(el, msg) {
        if (!el) return;
        el.textContent = msg;
        el.hidden = !msg;
    }

    function setMsg(el, msg) {
        if (!el) return;
        el.textContent = msg;
        el.hidden = !msg;
    }

    /* ── AJAX helper ─────────────────────────────────────────────────── */
    function post(action, data) {
        const body = new URLSearchParams({ action, nonce: NONCE, ...data });
        return fetch(AJAX, {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : body.toString(),
        }).then(r => r.json());
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── Button loading state ────────────────────────────────────────── */
    function btnLoading(btn) {
        btn._origHtml = btn.innerHTML;
        btn.innerHTML = '⏳';
        btn.classList.add('tnp-btn--loading');
        btn.disabled = true;
    }
    function btnRestore(btn) {
        if (btn._origHtml !== undefined) btn.innerHTML = btn._origHtml;
        btn.classList.remove('tnp-btn--loading');
        btn.disabled = false;
    }

    function slotToMinutes(t) {
        const parts = String(t).split(':');
        return parseInt(parts[0] || 0) * 60 + parseInt(parts[1] || 0);
    }
    function minutesToSlot(m) {
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    }

    /* ── Cell update helpers ─────────────────────────────────────────── */
    function applyBookingToCell(cell, data) {
        cell.dataset.id        = data.id           || '';
        cell.dataset.name      = data.player_name  || '';
        cell.dataset.cat       = data.category_id  || '';
        cell.dataset.duration  = data.duration     || 1;
        cell.dataset.recurring = data.recurring_id || 0;
        cell.dataset.trainer   = data.trainer_id   || '';
        cell.dataset.own       = '1';
        cell.dataset.canEdit   = '1';

        const bg = data.color      || '#999';
        const tc = data.text_color || '#fff';
        cell.style.background = bg;
        cell.style.color      = tc;

        cell.classList.remove('tnp-slot--free','tnp-slot--bookable');
        cell.classList.add('tnp-slot--booked','tnp-slot--editable');

        // Compute time range
        const startT    = cell.dataset.time || '';
        const dur       = parseInt(data.duration || 1);
        const endT      = startT ? minutesToSlot(slotToMinutes(startT) + dur * 30) : '';
        const timeStr   = (startT && endT) ? startT + ' – ' + endT : '';

        const playerName  = (data.player_name || '').trim();
        const catName     = (data.cat_name    || '').trim();
        const trainerName = (data.trainer_name || '').trim();
        const showPlayer  = playerName !== '' && playerName !== catName;
        const recur       = parseInt(data.recurring_id||0) > 0 ? '<span class="tnp-slot__recur">🔁</span>' : '';

        cell.innerHTML =
            (timeStr     ? '<span class="tnp-slot__time">'    + escHtml(timeStr)     + '</span>' : '') +
            (catName     ? '<span class="tnp-slot__cat">'     + escHtml(catName)     + '</span>' : '') +
            (trainerName ? '<span class="tnp-slot__trainer">👤 ' + escHtml(trainerName) + '</span>' : '') +
            (showPlayer  ? '<span class="tnp-slot__label">'   + escHtml(playerName)  + '</span>' :
             !catName    ? '<span class="tnp-slot__label">Belegt</span>' : '') +
            recur;
    }

    function clearCell(cell) {
        cell.dataset.id        = '';
        cell.dataset.name      = '';
        cell.dataset.cat       = '';
        cell.dataset.duration  = '1';
        cell.dataset.recurring = '0';
        cell.dataset.own       = '0';
        cell.dataset.canEdit   = '0';
        cell.dataset.onWaitlist= '0';
        cell.style.background  = '';
        cell.style.color       = '';
        cell.classList.remove('tnp-slot--booked','tnp-slot--editable');
        cell.classList.add('tnp-slot--free');
        if (LOGGED) cell.classList.add('tnp-slot--bookable');
        cell.innerHTML = '<span class="tnp-slot__free">+</span>';
    }

    /* ── Recurring toggle in book panel ─────────────────────────────── */
    const recurringToggle = document.getElementById('tnp-recurring-toggle');
    const recurringOpts   = document.getElementById('tnp-recurring-opts');
    const recPatternSels  = document.querySelectorAll('#tnp-rec-pattern');
    const recDowLabel     = document.getElementById('tnp-rec-dow-label');

    if (recurringToggle) {
        recurringToggle.addEventListener('change', function() {
            if (recurringOpts) recurringOpts.style.display = this.checked ? 'block' : 'none';
        });
    }
    recPatternSels.forEach(sel => {
        sel.addEventListener('change', function() {
            if (recDowLabel) recDowLabel.style.display = this.value === 'weekly' ? '' : 'none';
        });
    });

    /* ── Grid click handler ──────────────────────────────────────────── */
    const grid = document.getElementById('tnp-grid');
    if (!grid) return;

    grid.addEventListener('click', function(e) {
        const cell = e.target.closest('.tnp-slot');
        if (!cell) return;
        activeCell = cell;

        const booked   = cell.classList.contains('tnp-slot--booked');
        const blocked  = cell.classList.contains('tnp-slot--blocked');
        const canEdit  = cell.dataset.canEdit === '1';
        const cellDate = cell.dataset.date || DATE;

        if (blocked) return; // locked, no interaction

        if (!booked) {
            if (!LOGGED) {
                showPopup('guest');
            } else {
                const metaEl = document.getElementById('tnp-book-meta');
                if (metaEl) metaEl.textContent = cellDate + ' – ' + cell.dataset.time + ' Uhr';
                const catSel     = document.getElementById('tnp-cat');
                const nameFld    = document.getElementById('tnp-name');
                const durSel     = document.getElementById('tnp-duration');
                const trainerSel = document.getElementById('tnp-trainer');
                // Pre-check clicked court; uncheck all others (admin multi-court checkboxes)
                document.querySelectorAll('.tnp-book-court-cb').forEach(cb => {
                    cb.checked = (cb.value === (cell.dataset.court || ''));
                });
                if (catSel)      catSel.value     = '';
                // Auto-fill with logged-in user's display name (can be overridden)
                if (nameFld)     nameFld.value    = TennisPro.currentUserName || '';
                if (durSel)      durSel.value     = '1';
                if (trainerSel)  trainerSel.value = '';
                if (recurringToggle) recurringToggle.checked = false;
                if (recurringOpts)   recurringOpts.style.display = 'none';
                setError(document.getElementById('tnp-save-error'), '');
                showPopup('book');
            }
        } else if (canEdit) {
            const metaEl        = document.getElementById('tnp-edit-meta');
            const editTimeslot  = document.getElementById('tnp-edit-timeslot');
            const editDuration  = document.getElementById('tnp-edit-duration');
            const editCat       = document.getElementById('tnp-edit-cat');
            const editName      = document.getElementById('tnp-edit-name');
            const editTrainer   = document.getElementById('tnp-edit-trainer');
            const seriesOpts    = document.getElementById('tnp-recurring-delete-opts');
            if (metaEl)         metaEl.textContent  = cellDate + ' – ' + cell.dataset.time + ' Uhr';
            if (editTimeslot)   editTimeslot.value  = cell.dataset.time || '';
            if (editDuration)   editDuration.value  = cell.dataset.duration || '1';
            if (editCat)        editCat.value       = cell.dataset.cat || '';
            if (editName)       editName.value      = cell.dataset.name || '';
            if (editTrainer)    editTrainer.value   = cell.dataset.trainer || '';
            if (seriesOpts)   seriesOpts.style.display = parseInt(cell.dataset.recurring||0) > 0 ? 'block' : 'none';
            const cancelSeries = document.getElementById('tnp-cancel-series');
            if (cancelSeries) cancelSeries.checked = false;
            setError(document.getElementById('tnp-edit-error'), '');
            showPopup('edit');
        } else {
            // Booked by someone else – show view panel + waitlist
            const viewInfo = document.getElementById('tnp-view-info');
            if (viewInfo) viewInfo.textContent = cellDate + ' – ' + cell.dataset.time + ' Uhr – ' + (cell.dataset.name || 'Belegt');
            const joinBtn  = document.getElementById('tnp-waitlist-join-btn');
            const leaveBtn = document.getElementById('tnp-waitlist-leave-btn');
            const viewMsg  = document.getElementById('tnp-view-msg');
            const onWl     = cell.dataset.onWaitlist === '1';
            if (joinBtn)  joinBtn.style.display  = (LOGGED && !onWl) ? '' : 'none';
            if (leaveBtn) leaveBtn.style.display = (LOGGED &&  onWl) ? '' : 'none';
            setMsg(viewMsg, '');
            showPopup('view');
        }
    });

    /* ── Save ────────────────────────────────────────────────────────── */
    document.getElementById('tnp-save-btn')?.addEventListener('click', function() {
        if (!activeCell) return;
        btnLoading(this);

        const isRecurring = document.getElementById('tnp-recurring-toggle')?.checked;

        // Collect courts: checkboxes if present (admin multi-select), else clicked cell
        const allCbs     = document.querySelectorAll('.tnp-book-court-cb');
        const checkedCbs = Array.from(document.querySelectorAll('.tnp-book-court-cb:checked'));
        let courtIds;
        if (allCbs.length > 0) {
            if (checkedCbs.length === 0) {
                btnRestore(this);
                setError(document.getElementById('tnp-save-error'), 'Bitte mindestens einen Platz auswählen.');
                return;
            }
            courtIds = checkedCbs.map(cb => cb.value);
        } else {
            courtIds = [activeCell.dataset.court];
        }

        const basePayload = {
            timeslot   : activeCell.dataset.time,
            date       : activeCell.dataset.date || DATE,
            player_name: document.getElementById('tnp-name')?.value || '',
            category_id: document.getElementById('tnp-cat')?.value  || '',
            duration   : document.getElementById('tnp-duration')?.value || 1,
            recurring  : isRecurring ? '1' : '',
            trainer_id : document.getElementById('tnp-trainer')?.value || '',
        };

        if (isRecurring) {
            basePayload.rec_pattern     = document.getElementById('tnp-rec-pattern')?.value || 'weekly';
            basePayload.rec_day_of_week = document.getElementById('tnp-rec-dow')?.value     || '1';
            basePayload.rec_end_date    = document.getElementById('tnp-rec-end')?.value     || '';
        }

        // Single court + non-recurring → smooth cell-update path (no reload)
        if (courtIds.length === 1 && !isRecurring) {
            post('tennis_save', Object.assign({ court_id: courtIds[0] }, basePayload)).then(res => {
                btnRestore(this);
                if (res.success) {
                    if (parseInt(res.data.duration || 1) > 1) {
                        window.location.reload();
                    } else {
                        applyBookingToCell(activeCell, res.data);
                        closePopup();
                    }
                } else {
                    setError(document.getElementById('tnp-save-error'), res.data?.message || 'Fehler.');
                }
            }).catch(() => {
                btnRestore(this);
                setError(document.getElementById('tnp-save-error'), i18n.networkError);
            });
            return;
        }

        // Multiple courts or recurring → Promise.all + reload
        // Build court name map for error messages
        const courtNameMap = {};
        allCbs.forEach(cb => {
            const lbl = cb.closest('label');
            courtNameMap[cb.value] = lbl ? lbl.textContent.trim() : cb.value;
        });

        Promise.all(courtIds.map(cid =>
            post('tennis_save', Object.assign({ court_id: cid }, basePayload))
        )).then(results => {
            btnRestore(this);

            let anySuccess   = false;
            let allSkipped   = [];
            let totalCreated = 0;
            const errors     = [];

            results.forEach((res, i) => {
                if (res.success) {
                    anySuccess = true;
                    if (res.data.recurring) {
                        totalCreated += (res.data.created || 0);
                        (Array.isArray(res.data.skipped_slots) ? res.data.skipped_slots : []).forEach(s => allSkipped.push(s));
                    }
                } else {
                    const cName = courtNameMap[courtIds[i]] || courtIds[i];
                    errors.push(cName + ': ' + (res.data?.message || 'Fehler'));
                }
            });

            if (anySuccess) {
                const needAlert = (isRecurring && (allSkipped.length > 0 || errors.length > 0))
                               || (!isRecurring && errors.length > 0);
                if (needAlert) {
                    let msg = isRecurring
                        ? ('Serie angelegt: ' + totalCreated + ' Termin(e).')
                        : 'Teilweise gespeichert.';
                    if (errors.length > 0)
                        msg += '\n\nFehler:\n' + errors.join('\n');
                    if (allSkipped.length > 0) {
                        const lines = allSkipped.map(s =>
                            '• ' + escHtml(s.date_fmt || s.date) + ' · ' + escHtml(s.timeslot) + ' Uhr · ' + escHtml(s.court || '')
                        );
                        msg += '\n\nÜbersprungene Termine:\n' + lines.join('\n');
                    }
                    alert(msg);
                }
                closePopup();
                window.location.reload();
            } else {
                setError(document.getElementById('tnp-save-error'), errors.join(' · ') || 'Fehler.');
            }
        }).catch(() => {
            btnRestore(this);
            setError(document.getElementById('tnp-save-error'), i18n.networkError);
        });
    });

    /* ── Update ──────────────────────────────────────────────────────── */
    document.getElementById('tnp-update-btn')?.addEventListener('click', function() {
        if (!activeCell || !activeCell.dataset.id) return;
        btnLoading(this);

        const newTimeslot = document.getElementById('tnp-edit-timeslot')?.value || activeCell.dataset.time;
        const newDuration = parseInt(document.getElementById('tnp-edit-duration')?.value || activeCell.dataset.duration || 1);
        const clientSlotChanged = (
            newTimeslot !== activeCell.dataset.time ||
            newDuration !== parseInt(activeCell.dataset.duration || 1)
        );

        post('tennis_update', {
            id         : activeCell.dataset.id,
            player_name: document.getElementById('tnp-edit-name')?.value    || '',
            category_id: document.getElementById('tnp-edit-cat')?.value     || '',
            trainer_id : document.getElementById('tnp-edit-trainer')?.value ?? '',
            timeslot   : newTimeslot,
            duration   : newDuration,
        }).then(res => {
            btnRestore(this);
            if (res.success) {
                if (clientSlotChanged || res.data.slot_changed) {
                    window.location.reload();
                } else {
                    applyBookingToCell(activeCell, res.data);
                    closePopup();
                }
            } else {
                setError(document.getElementById('tnp-edit-error'), res.data?.message || 'Fehler.');
            }
        }).catch(() => {
            btnRestore(this);
            setError(document.getElementById('tnp-edit-error'), i18n.networkError);
        });
    });

    /* ── Delete ──────────────────────────────────────────────────────── */
    document.getElementById('tnp-delete-btn')?.addEventListener('click', function() {
        if (!activeCell || !activeCell.dataset.id) return;
        const cancelSeries = document.getElementById('tnp-cancel-series')?.checked;
        const msg = cancelSeries ? i18n.confirmSeries : i18n.confirmDelete;
        if (!confirm(msg)) return;
        btnLoading(this);

        post('tennis_delete', {
            id            : activeCell.dataset.id,
            cancel_series : cancelSeries ? '1' : '',
        }).then(res => {
            btnRestore(this);
            if (res.success) {
                if (cancelSeries || res.data.series_cancelled) {
                    window.location.reload();
                } else {
                    clearCell(activeCell);
                    closePopup();
                }
            } else {
                setError(document.getElementById('tnp-edit-error'), res.data?.message || 'Fehler.');
            }
        }).catch(() => {
            btnRestore(this);
            setError(document.getElementById('tnp-edit-error'), i18n.networkError);
        });
    });

    /* ── Waitlist helpers ────────────────────────────────────────────── */
    function appendWaitlistEntry(data) {
        const wrap = document.querySelector('.tnp-my-bookings');
        if (!wrap) return;

        let wlSection = wrap.querySelector('.tnp-my-bookings__waitlist');
        if (!wlSection) {
            const h4 = document.createElement('h4');
            h4.className = 'tnp-section-title';
            h4.textContent = 'Warteliste';
            wrap.appendChild(h4);
            wlSection = document.createElement('div');
            wlSection.className = 'tnp-my-bookings__waitlist';
            wrap.appendChild(wlSection);
            const hint = document.createElement('p');
            hint.className = 'tnp-my-bookings__hint';
            hint.textContent = 'Du erhältst eine E-Mail, sobald der Slot frei wird. Es gilt: wer zuerst bucht, bekommt den Slot.';
            wrap.appendChild(hint);
        }

        const entry = document.createElement('div');
        entry.className = 'tnp-waitlist-card';
        entry.dataset.wlCourt    = String(data.court_id || '');
        entry.dataset.wlDate     = data.date     || '';
        entry.dataset.wlTimeslot = data.timeslot || '';
        entry.innerHTML =
            '<span class="tnp-waitlist-badge">⏳ Warteliste</span>' +
            '<span class="tnp-booking-card__date">' + escHtml(data.date_label || data.date || '') + '</span>' +
            '<span class="tnp-booking-card__sep">·</span>' +
            escHtml((data.timeslot || '') + ' – ' + (data.end_time || '')) + ' Uhr' +
            '<span class="tnp-booking-card__sep">·</span>' +
            escHtml(data.court_name || '') +
            (data.player_name ? '<span class="tnp-booking-card__sep">·</span><em>' + escHtml(data.player_name) + '</em>' : '');
        wlSection.appendChild(entry);
    }

    function removeWaitlistEntry(courtId, date, timeslot) {
        const entries = document.querySelectorAll('.tnp-my-bookings__waitlist [data-wl-date]');
        entries.forEach(function(el) {
            if ( el.dataset.wlCourt    === String(courtId) &&
                 el.dataset.wlDate     === date &&
                 el.dataset.wlTimeslot === timeslot ) {
                el.remove();
            }
        });
    }

    /* ── Waitlist – Join ─────────────────────────────────────────────── */
    document.getElementById('tnp-waitlist-join-btn')?.addEventListener('click', function() {
        if (!activeCell) return;
        this.disabled = true;
        post('tennis_waitlist_join', {
            court_id : activeCell.dataset.court,
            date     : activeCell.dataset.date || DATE,
            timeslot : activeCell.dataset.time,
            duration : activeCell.dataset.duration || 1,
        }).then(res => {
            this.disabled = false;
            const msg = document.getElementById('tnp-view-msg');
            if (res.success) {
                activeCell.dataset.onWaitlist = '1';
                this.style.display = 'none';
                const leaveBtn = document.getElementById('tnp-waitlist-leave-btn');
                if (leaveBtn) leaveBtn.style.display = '';
                setMsg(msg, i18n.waitlistJoined);
                appendWaitlistEntry(res.data);
            } else {
                setMsg(msg, res.data?.message || 'Fehler.');
            }
        }).catch(() => {
            this.disabled = false;
        });
    });

    /* ── Waitlist – Leave ────────────────────────────────────────────── */
    document.getElementById('tnp-waitlist-leave-btn')?.addEventListener('click', function() {
        if (!activeCell) return;
        this.disabled = true;
        const courtId = activeCell.dataset.court;
        const leaveDate     = activeCell.dataset.date || DATE;
        const leaveTimeslot = activeCell.dataset.time;
        post('tennis_waitlist_leave', {
            court_id : courtId,
            date     : leaveDate,
            timeslot : leaveTimeslot,
        }).then(res => {
            this.disabled = false;
            const msg = document.getElementById('tnp-view-msg');
            if (res.success) {
                activeCell.dataset.onWaitlist = '0';
                this.style.display = 'none';
                const joinBtn = document.getElementById('tnp-waitlist-join-btn');
                if (joinBtn) joinBtn.style.display = '';
                setMsg(msg, i18n.waitlistLeft);
                removeWaitlistEntry(courtId, leaveDate, leaveTimeslot);
            } else {
                setMsg(msg, res.data?.message || 'Fehler.');
            }
        }).catch(() => {
            this.disabled = false;
        });
    });

    /* ── Close handlers ──────────────────────────────────────────────── */
    ['tnp-close','tnp-backdrop','tnp-cancel-guest','tnp-cancel-view','tnp-cancel-book','tnp-cancel-edit']
        .forEach(id => document.getElementById(id)?.addEventListener('click', closePopup));

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !popup.hidden) closePopup();
    });

})();
