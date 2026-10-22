import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const source = app.slice(app.indexOf('function parseLivewireAction('), app.indexOf('function adminConfirmElements('));
const resolve = runInNewContext(`${source}; resolveLivewireConfirmAction;`);

function trigger(expression, depth = 1) {
    let root = { getAttribute: () => 'parent', parentElement: null };
    for (let i = 0; i < depth; i++) {
        const parent = root;
        root = { getAttribute: () => `child-${i}`, parentElement: { closest: () => parent } };
    }
    return { getAttribute: () => expression, closest: () => root };
}

test('attendance deletion resolves to the enclosing parent component', () => {
    const action = resolve(trigger('$parent.deleteDay'));
    assert.equal(action.componentId, 'parent');
    assert.equal(action.method, 'deleteDay');
    assert.equal(action.params.length, 0);
});

test('local confirmation still targets its own component and preserves parameters', () => {
    const action = resolve(trigger('deleteDay(43)'));
    assert.equal(action.componentId, 'child-0');
    assert.equal(action.method, 'deleteDay');
    assert.equal(action.params[0], 43);
});

test('nested parent calls resolve each ancestor and preserve parameters', () => {
    const action = resolve(trigger('$parent.$parent.deleteDay(43)', 2));
    assert.equal(action.componentId, 'parent');
    assert.equal(action.params[0], 43);
});

test('missing parent cannot fall back to deleting from the child component', () => {
    assert.equal(resolve(trigger('$parent.deleteDay', 0)), null);
});
