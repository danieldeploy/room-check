import test from 'node:test';
import assert from 'node:assert/strict';
import { BrowserAdapter } from '../../windows/managed-ui/browser.mjs';
test('confirmed stopped Chrome is forgotten; an unavailable live Chrome is preserved', async () => {
  for (const present of [false, true]) {
    const adapter = new BrowserAdapter('/unused', null, async () => present);
    adapter.target = async () => { throw Error('connection_failed'); };
    if (present) await assert.rejects(adapter.inspect({profile:'fresh_login'}));
    else assert.deepEqual(await adapter.inspect({profile:'fresh_login'}), {exists:false});
  }
});
