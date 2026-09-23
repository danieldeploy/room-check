import test from 'node:test';
import assert from 'node:assert/strict';
import { publicLocation, candidateSelector, inspectPortalPage } from '../map-diagnostics.mjs';

test('URL hints omit credentials, query secrets and fragments', () => {
  assert.equal(publicLocation('booking', 'https://admin.booking.com/hotel/finance?hotel_id=539828&token=private#tab'),
    'https://admin.booking.com/hotel/finance?hotel_id=539828');
  assert.equal(publicLocation('expedia', 'https://www.expediapartnercentral.com/invoices?session=private'),
    'https://www.expediapartnercentral.com/invoices');
  assert.equal(publicLocation('booking', 'https://admin.booking.com/invoices/a1b2c3d4e5f6a7b8c9d0ef01?hotel_id=539828'),
    'https://admin.booking.com/invoices/:redacted?hotel_id=539828');
  assert.throws(() => publicLocation('booking', 'https://booking.com.evil.example/invoices'));
});
test('candidate selectors are restricted to simple stable identifiers', () => {
  assert.equal(candidateSelector('input', 'login-password'), '#login-password');
  assert.equal(candidateSelector('button', 'a:b', ['next', 'active']), 'button.next.active');
  assert.equal(candidateSelector('input', 'secret.value', ['x:y']), 'input');
  assert.equal(candidateSelector('input', 'a1b2c3d4e5f6a7b8', []), 'input');
});
test('inspection emits unvalidated structural hints and never serializes values or text', async () => {
  const page = {
    url: () => 'https://admin.booking.com/invoices?hotel_id=1140306&auth=secret',
    evaluate: async () => [
      { tag: 'input', id: 'user', classes: [], type: 'text', value: 'secret@example.com' },
      { tag: 'input', id: 'pass', classes: [], type: 'password', value: 'password-secret' },
      { tag: 'a', id: 'invoice', classes: [], href: 'https://admin.booking.com/pdf?token=private', text: 'invoice details' },
      { tag: 'a', id: 'foreign', classes: [], href: 'https://evil.example/private' },
    ],
  };
  const snapshot = await inspectPortalPage(page, 'booking');
  assert.equal(snapshot.validated, false);
  assert.equal(snapshot.location, 'https://admin.booking.com/invoices?hotel_id=1140306');
  assert.equal(snapshot.hints[2].href, 'https://admin.booking.com/pdf');
  assert.equal(snapshot.hints[3].href, undefined);
  for (const forbidden of ['secret@example.com', 'password-secret', 'invoice details', 'token=private', 'auth=secret']) {
    assert.ok(!JSON.stringify(snapshot).includes(forbidden));
  }
});
