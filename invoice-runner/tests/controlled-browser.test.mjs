import test from 'node:test';
import assert from 'node:assert/strict';
import { parseControlledEndpoint } from '../controlled-browser.mjs';

test('Booking control endpoint is pinned to local Chrome', () => {
  assert.equal(parseControlledEndpoint('38617\n/devtools/browser/12345678-abcd-1234-abcd-123456789012\n'),
    'ws://127.0.0.1:38617/devtools/browser/12345678-abcd-1234-abcd-123456789012');
  for (const value of ['0\n/devtools/browser/abcdefgh\n', '65536\n/devtools/browser/abcdefgh\n',
    '38617\n/devtools/page/abcdefgh\n', '38617\n//evil.example\n',
    '38617\n/devtools/browser/abc?token=x\n']) {
    assert.throws(() => parseControlledEndpoint(value));
  }
});
