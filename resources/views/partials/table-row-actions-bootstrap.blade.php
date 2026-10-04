<script data-table-row-actions-bootstrap>
    (() => {
        if (!window.matchMedia('(min-width: 768px)').matches) return;

        const controlSelector = 'a[href], button, input[type="button"], input[type="submit"]';
        const explicitLayoutSelector = [
            '.attendance-scan-list__table',
            '.attendance-days-table',
            '.attendance-records-table',
            '.attendance-day-groups-table',
            '.assessment-index-table',
            '.assessment-results-data-table',
            '.financial-transactions-table',
            '.student-notes-table',
            '[data-student-progress-juz-table]',
            '[data-curriculum-subject-resource-grid]',
        ].join(', ');

        const isAvailable = (action) => {
            if (
                !(action instanceof HTMLElement)
                || action.matches(':disabled, [aria-disabled="true"]')
                || action.closest('[hidden], [aria-hidden="true"]')
            ) return false;

            const style = window.getComputedStyle(action);

            return style.display !== 'none' && style.visibility !== 'hidden';
        };

        const isDelete = (action) => {
            const wireAction = action.getAttribute('wire:click')?.trim() ?? '';
            const label = [
                action.getAttribute('aria-label'),
                action.getAttribute('title'),
                action.textContent,
            ].filter(Boolean).join(' ');
            const hasDestructiveDataAttribute = Array.from(action.attributes).some((attribute) => (
                attribute.name.startsWith('data-') && /(?:delete|remove)/.test(attribute.name)
            ));

            return hasDestructiveDataAttribute
                || /^(?:\$wire\.)?(?:delete|destroy|remove)\b/i.test(wireAction)
                || /\b(?:delete|destroy|remove)\b/i.test(label)
                || /حذف|إزالة|ازالة/.test(label);
        };

        const hasExplicitLayout = (table) => table.querySelector(':scope > colgroup')
            || table.matches(explicitLayoutSelector)
            || window.getComputedStyle(table).tableLayout === 'fixed';

        const redistributeActionWidth = (table, headerCells, actionColumnIndex) => {
            if (!hasExplicitLayout(table)) return;

            const tableWidth = table.getBoundingClientRect().width;
            const columnWidths = headerCells.map((cell) => cell.getBoundingClientRect().width);
            const visibleColumnIndexes = headerCells.flatMap((cell, index) => (
                index !== actionColumnIndex && window.getComputedStyle(cell).display !== 'none' ? [index] : []
            ));
            const flexibleColumnIndexes = visibleColumnIndexes.filter((index) => (
                !headerCells[index].matches('[data-table-number-column], .table-cell-compact')
            ));
            const fixedWidth = visibleColumnIndexes
                .filter((index) => !flexibleColumnIndexes.includes(index))
                .reduce((total, index) => total + columnWidths[index], 0);
            const measuredFlexibleWidth = flexibleColumnIndexes
                .reduce((total, index) => total + columnWidths[index], 0);
            const availableFlexibleWidth = tableWidth - fixedWidth;

            if (
                tableWidth <= 0
                || flexibleColumnIndexes.length === 0
                || measuredFlexibleWidth <= 0
                || availableFlexibleWidth <= 0
            ) return;

            const columns = Array.from(table.querySelectorAll(':scope > colgroup > col'));
            const widthScale = availableFlexibleWidth / measuredFlexibleWidth;

            flexibleColumnIndexes.forEach((index) => {
                const width = `${(columnWidths[index] * widthScale / tableWidth) * 100}%`;
                const header = headerCells[index];
                const column = columns[index];

                header.classList.add('single-row-action__visible-header');
                header.style.setProperty('--single-row-action-column-width', width);

                if (column instanceof HTMLTableColElement) {
                    column.classList.add('single-row-action__visible-column');
                    column.style.setProperty('--single-row-action-column-width', width);
                }
            });
        };

        document.querySelectorAll('.app-main table').forEach((table) => {
            if (table.closest('.settings-admin-page')) return;

            const headerCells = Array.from(table.tHead?.rows?.[0]?.cells ?? []);
            const actionColumnIndex = headerCells.findIndex((cell) => cell.classList.contains('admin-actions-column'));

            if (actionColumnIndex < 0) return;

            const actionCells = [];
            let hasSingleActionRow = false;
            let hasAnyAction = false;
            let hasVisibleAction = false;

            Array.from(table.tBodies).flatMap((body) => Array.from(body.rows)).forEach((row) => {
                const actionCell = row.cells[actionColumnIndex];

                if (!(actionCell instanceof HTMLTableCellElement)) return;

                actionCells.push(actionCell);

                const actions = Array.from(actionCell.querySelectorAll(controlSelector)).filter(isAvailable);
                hasAnyAction ||= actions.length > 0;

                if (actions.length !== 1) {
                    hasVisibleAction ||= actions.length > 1;

                    return;
                }

                const action = actions[0];

                if (action.hasAttribute('data-keep-visible-table-action') || isDelete(action)) {
                    hasVisibleAction = true;

                    return;
                }

                hasSingleActionRow = true;
                const label = action.getAttribute('aria-label')
                    || action.getAttribute('title')
                    || action.textContent?.trim()
                    || '';

                row.setAttribute('data-single-row-action', '');
                action.classList.add('single-row-action__control');

                if (!row.hasAttribute('tabindex')) {
                    row.tabIndex = 0;
                    row.dataset.singleRowActionAddedTabindex = 'true';
                }

                if (label && !row.hasAttribute('aria-label')) {
                    row.setAttribute('aria-label', label);
                    row.dataset.singleRowActionAddedLabel = 'true';
                }
            });

            if (hasVisibleAction) {
                table.querySelectorAll('tbody > tr[data-single-row-action]').forEach((row) => {
                    row.querySelectorAll('.single-row-action__control').forEach((action) => {
                        action.classList.remove('single-row-action__control');
                    });
                    row.removeAttribute('data-single-row-action');

                    if (row.dataset.singleRowActionAddedTabindex === 'true') {
                        row.removeAttribute('tabindex');
                        delete row.dataset.singleRowActionAddedTabindex;
                    }

                    if (row.dataset.singleRowActionAddedLabel === 'true') {
                        row.removeAttribute('aria-label');
                        delete row.dataset.singleRowActionAddedLabel;
                    }
                });

                return;
            }

            if (!hasSingleActionRow && hasAnyAction) return;

            redistributeActionWidth(table, headerCells, actionColumnIndex);
            table.classList.add('table--single-row-actions');
            headerCells[actionColumnIndex]?.classList.add('single-row-action__header');
            actionCells.forEach((cell) => cell.classList.add('single-row-action__cell'));
            table.querySelector(`:scope > colgroup > :nth-child(${actionColumnIndex + 1})`)
                ?.classList.add('single-row-action__column');
        });
    })();
</script>
