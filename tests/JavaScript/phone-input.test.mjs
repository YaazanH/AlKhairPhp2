import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/components/phone-input.blade.php', import.meta.url), 'utf8');
const expression = template.match(/\bx-data="([\s\S]*?)"\r?\n>/)[1]
    .replace('@js($phoneCountries->values()->all())', JSON.stringify([
        { region: 'SY', dial_code: '+963', pattern: '### ### ###' },
        { region: 'US', dial_code: '+1', pattern: '(###) ###-####' },
    ]))
    .replace("@js($initial['region'] ?? 'SY')", "'SY'")
    .replace("@js($initial['national_number'] ?? '')", "''")
    .replaceAll('@js($model)', "'student_phone'");

function editor() {
    const events = [];
    const callbacks = [];
    let receive;
    let wireValue = '';
    const input = {
        value: '', selectionStart: 0, selectionEnd: 0, selectionDirection: 'none',
        setSelectionRange(start, end, direction) {
            this.selectionStart = start;
            this.selectionEnd = end;
            this.selectionDirection = direction;
        },
    };
    const hidden = { value: '', dispatchEvent() { events.push(this.value); wireValue = this.value; } };
    const document = { activeElement: input };
    const state = runInNewContext(`(${expression})`, { document, Event: class {} });
    state.$refs = { nationalPhone: input, fullPhone: hidden };
    state.$wire = { $get: () => wireValue };
    state.$nextTick = callback => callbacks.push(callback);
    state.$watch = (_expression, callback) => receive = callback;
    state.init();

    const flush = () => {
        input.value = state.nationalNumber;
        while (callbacks.length) callbacks.shift()();
    };
    const type = (value, start = value.length, end = start) => {
        input.value = value;
        input.setSelectionRange(start, end);
        state.syncPhone(value);
        flush();
    };
    return { state, input, hidden, events, document, type, flush, receive: (value, stale = false) => { if (! stale) wireValue = value; receive(value); flush(); } };
}

test('every typed digit is available to Save immediately, including before any server response', () => {
    const phone = editor();
    assert.deepEqual(phone.events, []);
    for (const digit of '933123456') phone.type(phone.input.value + digit);
    assert.equal(phone.input.value, '933 123 456');
    assert.equal(phone.hidden.value, '+963933123456');
    assert.equal(phone.input.selectionStart, 11);
});

test('formatting preserves insertion and selection positions inside the number', () => {
    const phone = editor();
    phone.type('933123456');
    phone.type('933 1923 456', 6);
    assert.equal(phone.input.value, '933 192 3456');
    assert.equal(phone.input.selectionStart, 6);
    phone.type('9331923456', 3, 7);
    assert.equal(phone.input.selectionStart, 3);
    assert.equal(phone.input.selectionEnd, 9);
});

test('international paste selects the country and keeps all national digits', () => {
    const phone = editor();
    phone.type('+1 (202) 555-0123');
    assert.equal(phone.state.region, 'US');
    assert.equal(phone.input.value, '(202) 555-0123');
    assert.equal(phone.hidden.value, '+12025550123');
});

test('a stale server value cannot overwrite a number being edited', () => {
    const phone = editor();
    phone.type('933123456');
    phone.receive('+963933', true);
    assert.equal(phone.input.value, '933 123 456');
    assert.equal(phone.hidden.value, '+963933123456');
});

test('a keyboard-submit reset clears the phone even while the input remains focused', () => {
    const phone = editor();
    phone.type('933123456');
    phone.receive('');
    assert.equal(phone.input.value, '');
    assert.equal(phone.hidden.value, '');
});

test('server-driven changes and Save-and-new reset the unfocused phone field', () => {
    const phone = editor();
    phone.type('933123456');
    phone.document.activeElement = null;
    phone.receive('+12025550123');
    assert.equal(phone.state.region, 'US');
    assert.equal(phone.input.value, '(202) 555-0123');
    phone.receive('');
    assert.equal(phone.input.value, '');
    assert.equal(phone.hidden.value, '');
});
