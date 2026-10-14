export function closeOtherCurriculumDetailsTables(openedTable) {
    if (!openedTable?.open || !openedTable.matches('[data-curriculum-group-details-table]')) return;

    const popupTables = openedTable.closest('[data-curriculum-group-details-tables]');
    popupTables?.querySelectorAll('[data-curriculum-group-details-table][open]').forEach((table) => {
        if (table !== openedTable) table.open = false;
    });
}
