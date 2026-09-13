import { Controller } from '@hotwired/stimulus';

/**
 * TableFilterController — Partie C
 * Composant réutilisable de recherche debounce + filtres par tableau.
 * Écrit en JavaScript ES6 pur (compatible avec AssetMapper sans compilation TypeScript).
 *
 * Usage (Twig) :
 *   <div data-controller="table-filter"
 *        data-table-filter-debounce-value="300"
 *        data-table-filter-table-id-value="myTable">
 *     <input data-table-filter-target="input" type="text" placeholder="Rechercher…">
 *     <table id="myTable">…</table>
 *   </div>
 */
export default class extends Controller {
    static values = {
        debounce:    { type: Number, default: 300 },
        tableId:     { type: String, default: '' },
    };

    static targets = ['input', 'count'];

    connect() {
        this._timer = null;
        this._onInput = this._onInput.bind(this);
        if (this.hasInputTarget) {
            this.inputTarget.addEventListener('input', this._onInput);
        }
        // Mobile accordion: repli du panneau de filtres
        this._initAccordion();
    }

    disconnect() {
        if (this._timer) window.clearTimeout(this._timer);
        if (this.hasInputTarget) {
            this.inputTarget.removeEventListener('input', this._onInput);
        }
    }

    // ── Écoute frappe ──────────────────────────────────────────────────────────
    _onInput(event) {
        const term = (event.target.value || '').trim();
        if (this._timer) window.clearTimeout(this._timer);
        this._timer = window.setTimeout(() => this.applyFilter(term), this.debounceValue);
    }

    // ── Déclenchement externe (ex. depuis un filtre <select>) ─────────────────
    filter() {
        const term = this.hasInputTarget ? (this.inputTarget.value || '').trim() : '';
        this.applyFilter(term);
    }

    // ── Filtre client (par défaut) ─────────────────────────────────────────────
    applyFilter(term) {
        const table = this._findTable();
        if (!table) return;

        const lower = term.toLowerCase();
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        let visible = 0;

        for (const row of rows) {
            // On saute les lignes décoratives / état vide / lignes de détail
            if (
                row.classList.contains('registry-detail') ||
                row.classList.contains('db-empty-state') ||
                row.classList.contains('detail-row') ||
                row.dataset.noFilter !== undefined
            ) {
                continue;
            }
            // Recherche dans data-filterable ou le texte complet de la ligne
            const text = (row.dataset.filterable || row.textContent || '').toLowerCase();
            const match = !lower || text.includes(lower);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        }

        // Mise à jour du compteur si présent
        if (this.hasCountTarget) {
            this.countTarget.textContent = visible + ' résultat' + (visible !== 1 ? 's' : '');
        }

        // Afficher un message "Aucun résultat" si besoin
        this._toggleEmptyState(table, visible);
    }

    // ── Accordéon mobile ───────────────────────────────────────────────────────
    _initAccordion() {
        const toggle = this.element.querySelector('[data-accordion-toggle]');
        const panel  = this.element.querySelector('[data-accordion-panel]');
        if (!toggle || !panel) return;

        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            panel.style.display = expanded ? 'none' : '';
            const icon = toggle.querySelector('[data-accordion-icon]');
            if (icon) icon.style.transform = expanded ? '' : 'rotate(180deg)';
        });

        // Sur mobile, replié par défaut (> 768px = déplié)
        if (window.innerWidth < 768) {
            panel.style.display = 'none';
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    _findTable() {
        if (this.tableIdValue) {
            return document.getElementById(this.tableIdValue);
        }
        return this.element.querySelector('table');
    }

    _toggleEmptyState(table, visibleCount) {
        let emptyRow = table.querySelector('tr[data-empty-filter]');
        const tbody  = table.querySelector('tbody');
        if (!tbody) return;

        if (visibleCount === 0) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.setAttribute('data-empty-filter', '');
                emptyRow.innerHTML =
                    '<td colspan="20" style="text-align:center;padding:24px;color:var(--text-muted,#94a3b8);font-style:italic">' +
                    '<i class="bi bi-search me-2"></i>Aucun résultat pour cette recherche.</td>';
                tbody.appendChild(emptyRow);
            }
            emptyRow.style.display = '';
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }
    }
}