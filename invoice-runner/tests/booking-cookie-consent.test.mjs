import test from 'node:test';
import assert from 'node:assert/strict';
import { rejectBookingOptionalCookies } from '../booking-cookie-consent.mjs';

function fixture({ visible = true, delayed = false, count = 1, stuck = false, clickError = false } = {}) {
  const state = { clicks: 0, checks: 0, waits: 0, visible };
  const page = {
    url: () => 'https://account.booking.com/sign-in',
    evaluate: async () => { state.checks++; return state.visible; },
    $$: async selector => {
      assert.equal(selector, '#onetrust-reject-all-handler');
      return Array.from({ length: count }, () => ({ evaluate: async () => true,
        click: async () => {
          state.clicks++;
          if (clickError) throw new Error('click failed');
          if (!stuck) state.visible = false;
        } }));
    },
    waitForFunction: async fn => {
      state.waits++;
      if (fn.name === 'visibleRejectButton' && delayed) state.visible = true;
      else if (fn.name === 'visibleRejectButton' || stuck) {
        const error = new Error('timeout'); error.name = 'TimeoutError'; throw error;
      }
      return { dispose: async () => {} };
    },
  };
  return { page, state };
}

test('a delayed Booking banner is rejected once and disappearance is awaited', async () => {
  const { page, state } = fixture({ visible: false, delayed: true });
  assert.equal(await rejectBookingOptionalCookies(page, { waitMs: 2500 }), true);
  assert.equal(state.clicks, 1); assert.equal(state.visible, false); assert.equal(state.waits, 2);
});
test('absence of the optional banner does not fail login', async () => {
  const { page, state } = fixture({ visible: false });
  assert.equal(await rejectBookingOptionalCookies(page, { waitMs: 2500 }), false);
  assert.equal(state.clicks, 0);
});
test('cookie controls are never clicked on other origins', async () => {
  for (const url of ['https://airbnb.com/', 'https://account.booking.com.evil.test/', 'http://account.booking.com/']) {
    const { page, state } = fixture(); page.url = () => url;
    assert.equal(await rejectBookingOptionalCookies(page), false);
    assert.equal(state.checks, 0); assert.equal(state.clicks, 0);
  }
});
test('ambiguous, broken or stuck rejection fails without accepting or retrying', async () => {
  for (const config of [{ count: 2 }, { stuck: true }, { clickError: true }]) {
    const { page, state } = fixture(config);
    await assert.rejects(rejectBookingOptionalCookies(page));
    assert.equal(state.clicks, config.count === 2 ? 0 : 1);
  }
});
