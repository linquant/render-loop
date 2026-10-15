(() => {
    'use strict';

    const $ = (sel) => document.querySelector(sel);
    let currentUrl = '';

    const flash = (msg, ok) => {
        const el = $('#hc-flash');
        el.textContent = msg;
        el.className = 'small mt-2 ' + (ok ? 'text-success' : 'text-danger');
    };
    const show = (sel) => $(sel).classList.remove('d-none');
    const hide = (sel) => $(sel).classList.add('d-none');

    async function postForm(url, data) {
        const res = await fetch(url, { method: 'POST', body: new URLSearchParams(data) });
        return res.json();
    }
    async function getJson(url) {
        const res = await fetch(url);
        return res.json();
    }

    // ─── Diff ───────────────────────────────────────────────────────────────
    function renderDiff(payload, title) {
        const pre = $('#hc-diff');
        pre.textContent = '';
        show('#hc-result');
        $('#hc-result-title').textContent = title;
        const s = payload.summary;
        $('#hc-result-summary').textContent = payload.identical
            ? 'aucune différence'
            : `+${s.lines_added} / -${s.lines_removed} · ${s.hunks} hunk(s)${s.truncated ? ' (tronqué)' : ''} · ${s.tags_touched.join(', ') || 'texte'}`;
        if (payload.identical) {
            pre.textContent = '✔ Aucune différence.';
            return;
        }
        for (const line of payload.diff.split('\n')) {
            const span = document.createElement('span');
            span.className = 'hc-line ' + (
                line.startsWith('+') ? 'hc-add'
                    : line.startsWith('-') ? 'hc-del'
                        : line.startsWith('@') ? 'hc-hunk' : '');
            span.textContent = line + '\n';
            pre.appendChild(span);
        }
    }

    // ─── Historique ─────────────────────────────────────────────────────────
    const picks = () => Array.from(document.querySelectorAll('.hc-pick:checked'));
    const updateCompareBtn = () => { $('#hc-compare').disabled = picks().length !== 2; };

    function actionButton(action, id, label, cls) {
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm ' + cls + ' ms-1';
        btn.textContent = label;
        btn.dataset.action = action;
        btn.dataset.id = id;
        return btn;
    }

    function renderHistory(list, baselineId) {
        const body = $('#hc-captures-body');
        body.innerHTML = '';
        $('#hc-count').textContent = String(list.length);

        const base = list.find((s) => s.id === baselineId);
        $('#hc-baseline').textContent = base ? `${base.tag || '(sans étiquette)'} — ${base.datetime}` : 'aucune';

        if (!list.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 4; td.className = 'text-center text-muted py-3';
            td.textContent = 'Aucune capture. Cliquez « Capturer un état » pour créer la référence.';
            tr.appendChild(td); body.appendChild(tr);
            updateCompareBtn();
            return;
        }

        for (const s of list) {
            const tr = document.createElement('tr');

            const tdCb = document.createElement('td');
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.className = 'hc-pick'; cb.value = s.id;
            tdCb.appendChild(cb);

            const tdDate = document.createElement('td');
            tdDate.textContent = s.datetime;

            const tdTag = document.createElement('td');
            const tag = document.createElement('span');
            tag.textContent = s.tag || '-';
            tdTag.appendChild(tag);
            if (s.is_baseline) {
                const star = document.createElement('span');
                star.className = 'badge bg-success ms-2';
                star.textContent = '★ référence';
                tdTag.appendChild(star);
            }

            const tdAct = document.createElement('td');
            tdAct.className = 'text-end';
            tdAct.appendChild(actionButton('view', s.id, 'Voir', 'btn-outline-secondary'));
            tdAct.appendChild(actionButton('baseline', s.id, '★ Référence', s.is_baseline ? 'btn-success' : 'btn-outline-success'));
            tdAct.appendChild(actionButton('delete', s.id, 'Suppr.', 'btn-outline-danger'));

            [tdCb, tdDate, tdTag, tdAct].forEach((c) => tr.appendChild(c));
            body.appendChild(tr);
        }
        updateCompareBtn();
    }

    async function loadHistory() {
        if (!currentUrl) { return; }
        const r = await getJson('?hc=api&url=' + encodeURIComponent(currentUrl));
        if (r.success) {
            renderHistory(r.snapshots, r.baseline);
            show('#hc-history');
        } else {
            flash(r.message || 'Échec du chargement de l\'historique.', false);
        }
    }

    // ─── Ouvrir une page ────────────────────────────────────────────────────
    $('#hc-open').addEventListener('click', async () => {
        const url = $('#hc-url').value.trim();
        if (!url) { flash('Saisissez une URL.', false); return; }
        currentUrl = url;
        $('#hc-current-url').textContent = url;
        show('#hc-context');
        hide('#hc-result');
        hide('#hc-view');
        await loadHistory();
        flash('Page ouverte.', true);
    });

    // ─── Capturer (rafraîchit l'historique, sans recharger la page) ──────────
    $('#hc-capture').addEventListener('click', async () => {
        const r = await postForm('?hc=capture', { url: currentUrl, tag: $('#hc-tag').value.trim() });
        if (r.success) { flash('Capture enregistrée.', true); await loadHistory(); }
        else { flash(r.message || 'Échec de la capture.', false); }
    });

    // ─── Recontrôler ────────────────────────────────────────────────────────
    $('#hc-recheck').addEventListener('click', async () => {
        const r = await postForm('?hc=recheck', { url: currentUrl });
        if (r.success) {
            renderDiff(r, 'Recontrôle (vs référence)');
            await loadHistory();
        } else {
            flash(r.message || 'Échec du recontrôle.', false);
        }
    });

    // ─── Comparer 2 captures cochées ────────────────────────────────────────
    $('#hc-compare').addEventListener('click', async () => {
        const [a, b] = picks().map((cb) => cb.value);
        const r = await getJson('?hc=compare&from=' + encodeURIComponent(a) + '&to=' + encodeURIComponent(b));
        if (r.success) { renderDiff(r, 'Comparaison'); }
        else { flash(r.message || 'Échec de la comparaison.', false); }
    });

    // ─── Actions par ligne (délégation : lignes recréées à chaque chargement) ─
    $('#hc-captures-body').addEventListener('change', (ev) => {
        const t = ev.target;
        if (!t.classList || !t.classList.contains('hc-pick')) { return; }
        if (picks().length > 2) { t.checked = false; return; }
        updateCompareBtn();
    });

    $('#hc-captures-body').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('button[data-action]');
        if (!btn) { return; }
        const id = btn.dataset.id;
        if (btn.dataset.action === 'view') {
            const r = await getJson('?hc=snapshot&id=' + encodeURIComponent(id));
            if (r.success) {
                $('#hc-view-title').textContent = 'HTML normalisé — ' + (r.tag || r.datetime);
                $('#hc-view-pre').textContent = r.html;
                show('#hc-view');
            } else { flash(r.message || 'Capture introuvable.', false); }
        } else if (btn.dataset.action === 'baseline') {
            const r = await postForm('?hc=baseline', { url: currentUrl, id });
            if (r.success) { flash('Référence définie.', true); await loadHistory(); }
            else { flash(r.message || 'Échec.', false); }
        } else if (btn.dataset.action === 'delete') {
            if (!window.confirm('Supprimer cette capture ?')) { return; }
            const r = await postForm('?hc=delete', { id });
            if (r.success) { flash('Capture supprimée.', true); await loadHistory(); }
            else { flash(r.message || 'Échec de la suppression.', false); }
        }
    });

    $('#hc-view-close').addEventListener('click', () => hide('#hc-view'));
})();
