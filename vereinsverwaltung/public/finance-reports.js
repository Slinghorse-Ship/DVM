window.KulturhusPage.register('finance-reports', pageContext => {
(() => {
    'use strict';
    const root = document.querySelector('[data-finance-reports]');
    if (!root) return;
    const messages=JSON.parse(root.dataset.reportMessages||'{}');
    const text=(source,values={})=>(messages[source]??source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
    const form = root.querySelector('#finance-report-filters');
    const from = form.elements.namedItem('from');
    const to = form.elements.namedItem('to');
    const setCollapsed = (collapsed) => {
        root.classList.toggle('report-filters-collapsed', collapsed);
        root.querySelectorAll('[data-report-filter-toggle]').forEach((button) => {
            button.setAttribute('aria-expanded', String(!collapsed));
        });
    };
    const mobile = window.matchMedia('(max-width: 760px)');
    setCollapsed(mobile.matches);
    root.querySelectorAll('[data-report-filter-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const collapsed = !root.classList.contains('report-filters-collapsed');
            setCollapsed(collapsed);
            if (collapsed) root.querySelector('.report-filter-reopen').focus();
            else root.querySelector('#report-type').focus();
        });
    });
    const iso = (year, month, day) => `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const monthEnd = (year, month) => new Date(Date.UTC(year, month, 0)).getUTCDate();
    const selectedYear = () => Number(/^\d{4}/.exec(from.value)?.[0] || new Date().getFullYear());
    const updatePeriodState = () => {
        const year = selectedYear();
        root.querySelectorAll('[data-report-year]').forEach((link) => {
            if (Number(link.dataset.reportYear) === year) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        root.querySelectorAll('[data-report-quarter], [data-report-month]').forEach((link) => {
            const first = link.dataset.reportQuarter ? (Number(link.dataset.reportQuarter) - 1) * 3 + 1 : Number(link.dataset.reportMonth);
            const last = link.dataset.reportQuarter ? first + 2 : first;
            if (from.value === iso(year, first, 1) && to.value === iso(year, last, monthEnd(year, last))) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
            const destination = new URL(link.href);
            destination.searchParams.set('from', iso(year, first, 1));
            destination.searchParams.set('to', iso(year, last, monthEnd(year, last)));
            link.href = destination.toString();
        });
        to.min = from.value;
        from.max = to.value;
    };
    root.querySelectorAll('[data-report-year], [data-report-quarter], [data-report-month]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            const year = link.dataset.reportYear ? Number(link.dataset.reportYear) : selectedYear();
            const first = link.dataset.reportYear ? 1 : link.dataset.reportQuarter ? (Number(link.dataset.reportQuarter) - 1) * 3 + 1 : Number(link.dataset.reportMonth);
            const last = link.dataset.reportYear ? 12 : link.dataset.reportQuarter ? first + 2 : first;
            from.value = iso(year, first, 1);
            to.value = iso(year, last, monthEnd(year, last));
            updatePeriodState();
        });
    });
    from.addEventListener('input', updatePeriodState);
    to.addEventListener('input', updatePeriodState);
    updatePeriodState();
    root.querySelectorAll('[data-report-setting]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            form.elements.namedItem(link.dataset.reportSetting).value = link.dataset.reportValue;
            form.requestSubmit();
        });
    });
    root.querySelector('[data-report-type]').addEventListener('change', () => form.requestSubmit());
    root.querySelector('[data-report-print]').addEventListener('click', () => window.print());
    const selectRow = (row) => {
        root.querySelectorAll('[data-report-row]').forEach((other) => {
            const selected = row === other;
            other.classList.toggle('is-selected', selected);
        });
    };
    root.querySelectorAll('[data-report-row]').forEach((row) => {
        row.addEventListener('click', () => selectRow(row));
        row.addEventListener('keydown', (event) => {
            if (event.target === row && ['Enter', ' '].includes(event.key)) {
                event.preventDefault();
                selectRow(row);
            }
        });
    });
    const table = root.querySelector('.report-table');
    if (table) {
        const rows = Array.from(table.querySelectorAll('[data-report-row]'));
        const columnOrder = JSON.parse(root.dataset.reportColumnOrder);
        const options = Array.from(root.querySelectorAll('[data-report-column-option]'));
        const visible = new Set(options.filter((option) => option.checked).map((option) => option.dataset.reportColumnOption));
        const header = table.tHead.rows[0];
        columnOrder.push('tags');
        [header, ...rows].forEach((row) => {
            Array.from(row.cells).forEach((cell, index) => { cell.dataset.reportColumn = columnOrder[index]; });
        });
        const noMatches = document.createElement('tr');
        const noMatchesCell = document.createElement('td');
        noMatchesCell.className = 'report-empty';
        noMatchesCell.textContent = text('Keine passenden Zeilen gefunden.');
        noMatches.append(noMatchesCell);
        noMatches.hidden = true;
        table.tBodies[0].append(noMatches);
        const applyColumns = () => {
            table.querySelectorAll('[data-report-column]').forEach((cell) => { cell.hidden = !visible.has(cell.dataset.reportColumn); });
            noMatchesCell.colSpan = visible.size;
            table.querySelectorAll('.report-empty').forEach((cell) => { cell.colSpan = visible.size; });
        };
        applyColumns();
        const dialog = root.querySelector('#report-columns-dialog');
        const restoreOptions = () => options.forEach((option) => { option.checked = visible.has(option.dataset.reportColumnOption); });
        root.querySelector('[data-report-columns-open]').addEventListener('click', () => {
            restoreOptions();
            dialog.showModal();
        });
        root.querySelectorAll('[data-report-columns-cancel]').forEach((button) => button.addEventListener('click', () => {
            restoreOptions();
            dialog.close();
        }));
        dialog.addEventListener('cancel', restoreOptions);
        root.querySelector('[data-report-columns-save]').addEventListener('click', () => {
            visible.clear();
            options.forEach((option) => { if (option.disabled || option.checked) visible.add(option.dataset.reportColumnOption); });
            applyColumns();
            dialog.close();
        });
        const searchToggle = root.querySelector('[data-report-search-toggle]');
        const searchPanel = root.querySelector('#report-table-search');
        const search = root.querySelector('[data-report-search]');
        const searchStatus = root.querySelector('[data-report-search-status]');
        const applySearch = () => {
            const query = search.value.trim().toLocaleLowerCase('de-DE');
            let matches = 0;
            rows.forEach((row) => {
                row.hidden = query !== '' && !row.textContent.toLocaleLowerCase('de-DE').includes(query);
                if (!row.hidden) matches += 1;
                else row.classList.remove('is-selected');
            });
            noMatches.hidden = query === '' || matches > 0 || rows.length === 0;
            searchStatus.textContent = text(query?'{matches} von {total} Zeilen gefunden. Ergebnis und Export: gesamter Zeitraum.':'{matches} von {total} Zeilen. Ergebnis und Export: gesamter Zeitraum.',{matches,total:rows.length});
        };
        searchToggle.addEventListener('click', () => {
            searchPanel.hidden = !searchPanel.hidden;
            searchToggle.setAttribute('aria-expanded', String(!searchPanel.hidden));
            if (!searchPanel.hidden) search.focus();
            else { search.value = ''; applySearch(); }
        });
        root.querySelector('[data-report-search-apply]').addEventListener('click', applySearch);
        root.querySelector('[data-report-search-clear]').addEventListener('click', () => { search.value = ''; applySearch(); search.focus(); });
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); applySearch(); }
        });
        search.addEventListener('input', () => { if (search.value === '') applySearch(); });
    }
    const tagSearch = root.querySelector('[data-report-tag-search]');
    if (tagSearch) tagSearch.addEventListener('input', () => {
        const query = tagSearch.value.trim().toLocaleLowerCase();
        const choices = Array.from(root.querySelectorAll('[data-report-tag-choice]'));
        choices.forEach((choice) => { choice.hidden = !choice.textContent.toLocaleLowerCase().includes(query); });
        const empty = root.querySelector('[data-report-no-tags]');
        empty.hidden = choices.some((choice) => !choice.hidden);
        empty.textContent = query ? text('Keine passenden Tags vorhanden') : text('Keine Tags vorhanden');
    });
})();
});
