export const BOOKING_AUTH_ORIGINS = Object.freeze([
  'https://account.booking.com', 'https://auth.booking.com', 'https://admin.booking.com',
]);

// Native Chrome permission: no page button/coordinates, no global permission reset.
export async function blockBookingLoopback(browser, enabled = true) {
  if (!enabled) return { status: 'disabled', release: async () => {} };
  let session;
  try {
    session = await browser.target().createCDPSession();
    for (const origin of BOOKING_AUTH_ORIGINS) {
      await session.send('Browser.setPermission', {
        permission: { name: 'loopback-network' }, setting: 'denied', origin,
      });
    }
    return { status: 'blocked', release: () => session.detach().catch(() => {}) };
  } catch {
    if (session) await session.detach().catch(() => {});
    return { status: 'unavailable', release: async () => {} };
  }
}
