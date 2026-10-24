import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const head = readFileSync(new URL('../../resources/views/partials/head.blade.php', import.meta.url), 'utf8');
const script = head.match(/<script data-app-asset-version>([\s\S]*?)<\/script>/)[1];

test('Livewire navigation reloads when the build changes rather than mixing old and new handlers', () => {
    let reloads = 0;
    const window = { location: { reload: () => reloads++ } };
    const visit = version => runInNewContext(script.replace('@js($appAssetVersion)', JSON.stringify(version)), { window });
    visit('first-build');
    visit('first-build');
    assert.equal(reloads, 0);
    visit('next-build');
    assert.equal(reloads, 1);
});

test('development without a production manifest does not cause a reload loop', () => {
    let reloads = 0;
    const window = { location: { reload: () => reloads++ } };
    runInNewContext(script.replace('@js($appAssetVersion)', 'null'), { window });
    assert.equal(reloads, 0);
});
