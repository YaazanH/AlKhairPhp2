import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupOptionColumns, fitGroupOptionColumnWidths, widestGroupOptionColumnWidths, stableGroupOptionRowWidth, fitGroupOptionText, containedGroupMenu } from '../../resources/js/group-option-layout.js';

test('phone menu opens above a field near the screen bottom and stays inside horizontal edges', () => {
    const bounds = { top: 80, bottom: 820, left: 8, right: 382 };
    const menu = containedGroupMenu({ top: 745, bottom: 795, left: 24, width: 400 }, bounds);
    assert.equal(menu.openAbove, true);
    assert.equal(menu.height, 240);
    assert.ok(menu.left >= bounds.left);
    assert.ok(menu.left + menu.width <= bounds.right);
});

test('phone menu height follows the available area above the on-screen keyboard', () => {
    const bounds = { top: 80, bottom: 390, left: 8, right: 382 };
    const menu = containedGroupMenu({ top: 250, bottom: 300, left: 24, width: 342 }, bounds);
    assert.equal(menu.openAbove, true);
    assert.equal(menu.height, 164);
    assert.ok(250 - menu.gap - menu.height >= bounds.top);
    const below = containedGroupMenu({ top: 90, bottom: 140, left: 24, width: 342 }, bounds);
    assert.equal(below.openAbove, false);
    assert.ok(140 + below.gap + below.height <= bounds.bottom);
});

test('phones show only the group name; larger screens retain the available columns', () => {
    const columns = JSON.stringify(['حلقة الأنوار', 'الصف الثامن', 'دورة الشتاء']);
    assert.deepEqual(groupOptionColumns(columns, true), ['حلقة الأنوار']);
    assert.deepEqual(groupOptionColumns(columns, false), ['حلقة الأنوار', 'الصف الثامن', 'دورة الشتاء']);
    assert.deepEqual(groupOptionColumns(JSON.stringify(['حلقة الأنوار', 'الصف الثامن']), false), ['حلقة الأنوار', 'الصف الثامن']);
});

test('group option columns keep independent natural widths when they fit', () => {
    assert.deepEqual(fitGroupOptionColumnWidths([142.2, 268.4], 520), [143, 269]);
});

test('group option columns shrink proportionally while reserving equal outer and middle gaps', () => {
    assert.deepEqual(fitGroupOptionColumnWidths([300, 500], 700), [244, 408]);
});

test('selected group and course columns keep widths from every available option', () => {
    const options = [
        ['حلقة أسوة', 'دورة شتاء 2026 - 2027 س'],
        ['حلقة أحباب الرحمن', 'دورة صيف 2026 س'],
        ['حلقة المفلحون', 'دورة ربيع طويلة 2027 س'],
    ];
    const widths = widestGroupOptionColumnWidths(options, value => Array.from(value).length);

    assert.deepEqual(widths, [17, 23]);
    assert.deepEqual(
        widestGroupOptionColumnWidths([options[0]], value => Array.from(value).length),
        [9, 23],
    );
});

test('group and course anchors keep the textbox width when the dropdown becomes hidden', () => {
    assert.equal(stableGroupOptionRowWidth(800, 12, 2), 786);
    assert.equal(stableGroupOptionRowWidth(640, 12, 2), 626);
    assert.equal(stableGroupOptionRowWidth(10, 12, 2), 0);
});

test('justification chooses the longest fitting text with a bounded number of measurements', () => {
    let calls = 0;
    const measure = text => { calls++; return text.length; };
    assert.equal(fitGroupOptionText('abc', 30, count => 'abc' + '_'.repeat(count), measure), 'abc' + '_'.repeat(27));
    assert.ok(calls <= 7, `Measured ${calls} times`);
});

test('long text is left intact and no extension is added if there is no room', () => {
    assert.equal(fitGroupOptionText('long name', 4, () => assert.fail('No justification expected'), text => text.length), 'long name');
    assert.equal(fitGroupOptionText('abc', 3.5, count => 'abc' + '_'.repeat(count), text => text.length), 'abc');
});
