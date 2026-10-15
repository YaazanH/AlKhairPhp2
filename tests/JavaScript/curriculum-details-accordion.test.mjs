import assert from 'node:assert/strict';
import test from 'node:test';

import { closeOtherCurriculumDetailsTables } from '../../resources/js/curriculum-details-accordion.js';

function curriculumTable(open = true) {
    return {
        open,
        matches: (selector) => selector === '[data-curriculum-group-details-table]',
        closest: () => null,
    };
}

test('opening a curriculum table closes the other open table in the same popup', () => {
    const openedTable = curriculumTable();
    const siblingTable = curriculumTable();
    const otherPopupTable = curriculumTable();
    openedTable.closest = () => ({
        querySelectorAll: () => [openedTable, siblingTable],
    });

    closeOtherCurriculumDetailsTables(openedTable);

    assert.equal(openedTable.open, true);
    assert.equal(siblingTable.open, false);
    assert.equal(otherPopupTable.open, true);
});

test('closing a curriculum table does not change its siblings', () => {
    const closedTable = curriculumTable(false);
    const siblingTable = curriculumTable();
    closedTable.closest = () => ({
        querySelectorAll: () => [closedTable, siblingTable],
    });

    closeOtherCurriculumDetailsTables(closedTable);

    assert.equal(siblingTable.open, true);
});
