import assert from 'node:assert/strict';
import { test } from 'node:test';
import { debounceSearch } from '../../resources/js/search-debounce.js';

test('search waits 500ms after the latest keystroke and only applies the latest query', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const queries = [];
    const search = debounceSearch((query) => queries.push(query));

    search('أ');
    t.mock.timers.tick(300);
    search('أح');
    t.mock.timers.tick(499);
    assert.deepEqual(queries, []);
    t.mock.timers.tick(1);
    assert.deepEqual(queries, ['أح']);
    t.mock.timers.tick(500);
    assert.deepEqual(queries, ['أح']);
});

test('closing a dropdown cancels pending filtering', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let calls = 0;
    const search = debounceSearch(() => calls++);
    search();
    t.mock.timers.tick(200);
    search.cancel();
    t.mock.timers.tick(500);
    assert.equal(calls, 0);
});

test('keyboard selection can apply the current query immediately without a later duplicate', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const queries = [];
    const search = debounceSearch((query) => queries.push(query));
    search('Ahmed');
    t.mock.timers.tick(100);
    search.flush();
    assert.deepEqual(queries, ['Ahmed']);
    t.mock.timers.tick(500);
    search.flush();
    assert.deepEqual(queries, ['Ahmed']);
});

test('separate search inputs have independent timers', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const queries = [];
    const first = debounceSearch((query) => queries.push(query));
    const second = debounceSearch((query) => queries.push(query));
    first('student');
    t.mock.timers.tick(200);
    second('teacher');
    t.mock.timers.tick(300);
    assert.deepEqual(queries, ['student']);
    t.mock.timers.tick(200);
    assert.deepEqual(queries, ['student', 'teacher']);
});
