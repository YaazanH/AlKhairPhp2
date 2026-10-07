import test from 'node:test';
import assert from 'node:assert/strict';
import { addressCompletion, addressInput } from '../../resources/js/address-completion.js';

test('inline completion preserves typed Arabic and accepts canonical spelling', () => {
    const place = { value: 'دمشق - أبو رمانة', aliases: [] };
    assert.equal(addressCompletion('دم', place), 'شق - أبو رمانة');
    assert.equal(addressCompletion('ابو', place), ' رمانة');
    assert.equal(addressCompletion('دمشق - ', place), 'أبو رمانة');
    assert.equal(addressCompletion('رما', place), 'نة');
    assert.equal(addressCompletion('دمشق - المزة', place), '');
    assert.equal(addressCompletion('دمشق ابو', place), ' رمانة');
    assert.equal(addressCompletion('دمشق-ابو', place), ' رمانة');
    assert.equal(addressCompletion('دمشق — أبو', place), ' رمانة');
    assert.equal(addressCompletion(place.value, place), '');
});

test('Tab accepts the visible suggestion and leaves the field ready for details', () => {
    const field = addressInput({ value: 'دمشق ابو', places: [{ value: 'دمشق - أبو رمانة', aliases: [] }], endpoint: '/address-suggestions' });
    let prevented = false;
    let notified = false;
    let selection;
    field.focused = true;
    field.$refs = { input: { value: field.query, dispatchEvent: () => { notified = true; }, focus() {}, setSelectionRange: (...args) => { selection = args; } } };
    field.accept({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(notified, true);
    assert.equal(field.$refs.input.value, 'دمشق - أبو رمانة - ');
    assert.deepEqual(selection, [field.query.length, field.query.length]);
    assert.equal(field.suffix, '');
});

test('an old online response cannot replace results for a newer query', async () => {
    const originalFetch = globalThis.fetch;
    const originalWindow = globalThis.window;
    let resolve;
    globalThis.window = { location: { origin: 'https://example.test' } };
    globalThis.fetch = () => new Promise(r => { resolve = r; });
    try {
        const field = addressInput({ value: 'دمشق', places: [], endpoint: '/address-suggestions' });
        field.generation = 1;
        const pending = field.lookup('دمشق', 1);
        field.query = 'حمص'; field.generation = 2;
        resolve({ ok: true, json: async () => ({ suggestions: [{ value: 'دمشق - المزة' }] }) });
        await pending;
        assert.deepEqual(field.remote, []);
    } finally { globalThis.fetch = originalFetch; globalThis.window = originalWindow; }
});
