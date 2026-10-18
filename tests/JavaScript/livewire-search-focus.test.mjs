import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const source = app.slice(app.indexOf('function livewireBoundModelName('), app.indexOf('const mobileTableFilterObserver'));

function browser() {
    const hooks = {};
    const frames = [];
    const inputs = [];
    const document = {
        body: {},
        activeElement: null,
        getElementById: (id) => inputs.find(input => input.id === id),
        addEventListener: (_name, callback) => callback(),
    };
    class Input {
        constructor(id, model, value) {
            this.id = id;
            this.attributes = [{ name: 'wire:model.live.debounce.500ms', value: model }];
            this.value = value;
            this.setSelectionRange(value.length, value.length);
        }
        focus() { document.activeElement = this; }
        setSelectionRange(start, end, direction = 'none') {
            this.selectionStart = start;
            this.selectionEnd = end;
            this.selectionDirection = direction;
        }
    }
    const component = { el: { contains: input => inputs.includes(input), querySelectorAll: () => inputs } };
    const input = new Input('student-search', 'search', 'أحمد');
    inputs.push(input);
    input.focus();
    runInNewContext(source, {
        document,
        HTMLInputElement: Input,
        window: {
            Livewire: { hook: (name, callback) => hooks[name] = callback },
            requestAnimationFrame: callback => frames.push(callback),
        },
    });
    return {
        input, document, inputs, component,
        commit() { hooks.commit?.({component, succeed: callback => frames.push(callback)}); },
        morph() { hooks.morph?.({component}); },
        replace() {
            const replacement = new Input(input.id, 'search', input.value);
            inputs[0] = replacement;
            document.activeElement = document.body;
            return replacement;
        },
        finish() {
            hooks.morphed?.({component});
            while (frames.length) frames.shift()();
        },
    };
}

test('Arabic typing during a request restores the latest caret after input replacement', () => {
    const ui = browser();
    ui.commit();
    ui.input.value += ' عبد الرحمن';
    const end = ui.input.value.length;
    ui.input.setSelectionRange(end, end);
    ui.morph();
    const replacement = ui.replace();
    ui.finish();
    assert.equal(ui.document.activeElement, replacement);
    assert.equal(replacement.selectionStart, end);
    assert.equal(replacement.selectionEnd, end);
});

test('moving the caret during a request preserves an intentional edit inside a name', () => {
    const ui = browser();
    ui.commit();
    ui.input.setSelectionRange(1, 3, 'backward');
    ui.morph();
    const replacement = ui.replace();
    ui.finish();
    assert.equal(replacement.selectionStart, 1);
    assert.equal(replacement.selectionEnd, 3);
    assert.equal(replacement.selectionDirection, 'backward');
});

test('a refresh that keeps the focused input does not override its caret', () => {
    const ui = browser();
    ui.morph();
    ui.input.setSelectionRange(2, 2);
    ui.finish();
    assert.equal(ui.input.selectionStart, 2);
});

test('moving to another control while waiting for results does not refocus search', () => {
    const ui = browser();
    ui.commit();
    const button = {};
    ui.document.activeElement = button;
    ui.morph();
    ui.finish();
    assert.equal(ui.document.activeElement, button);
});

test('moving to another control before the animation frame does not steal focus', () => {
    const ui = browser();
    ui.morph();
    ui.replace();
    const button = {};
    ui.document.activeElement = button;
    ui.finish();
    assert.equal(ui.document.activeElement, button);
});

test('a removed search input is not restored', () => {
    const ui = browser();
    ui.morph();
    ui.inputs.length = 0;
    ui.document.activeElement = ui.document.body;
    ui.finish();
    assert.equal(ui.document.activeElement, ui.document.body);
});

test('an update to another component does not capture this search input', () => {
    const ui = browser();
    ui.component.el.contains = () => false;
    ui.morph();
    ui.replace();
    ui.finish();
    assert.equal(ui.document.activeElement, ui.document.body);
});
