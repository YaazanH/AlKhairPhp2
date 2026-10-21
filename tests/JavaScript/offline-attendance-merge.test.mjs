import assert from 'node:assert/strict';
import test from 'node:test';

import { shouldRefreshMergedAttendance } from '../../resources/js/offline-attendance-merge.js';

test('an offline attendance upload refreshes after the pending outbox is fully merged', () => {
    assert.equal(shouldRefreshMergedAttendance(
        [{ queued_offline: true }],
        [],
    ), true);
});

test('ordinary online attendance changes do not refresh the page', () => {
    assert.equal(shouldRefreshMergedAttendance(
        [{ queued_offline: false }],
        [],
    ), false);
});

test('a newer pending change finishes syncing before the merged page refreshes', () => {
    assert.equal(shouldRefreshMergedAttendance(
        [{ queued_offline: true }],
        [{ state: 'pending' }],
    ), false);
});

test('legacy queued operations are treated as offline changes', () => {
    assert.equal(shouldRefreshMergedAttendance(
        [{}],
        [{ state: 'conflict' }],
    ), true);
});
