// Shared close policy. Provenance and safety are positive evidence, not guesses.
export const IDLE_MS = 30 * 60 * 1000;
export function decision(item, observation, now, completed = false) {
  if (!item || item.owned !== true || !Number.isFinite(now)) return 'preserve';
  if (observation?.exists === false || observation?.identity === false) return 'forget';
  if (item.protected === true || item.busyUntil > now) return 'preserve';
  if (observation?.identity !== true || observation?.safe !== true || observation?.busy !== false) return 'preserve';
  const last = Math.max(item.lastUsedAt, observation.lastActivity || 0);
  if (!Number.isFinite(last) || last > now || last <= 0) return 'preserve';
  return completed || now - last >= IDLE_MS ? 'close' : 'preserve';
}
export function needsSchedule(items) {
  return Array.isArray(items) && items.some(item => item.owned === true && !item.protected);
}
export async function sweep(items, adapter, now = Date.now(), completed = false) {
  const remaining = []; let closed = 0; let forgotten = 0;
  for (const original of items) {
    const item = { ...original };
    let observation;
    try { observation = await adapter.inspect(item); } catch { observation = null; }
    const action = decision(item, observation, now, completed);
    if (Number.isFinite(observation?.lastActivity) && observation.lastActivity > 0 && observation.lastActivity <= now) {
      item.lastUsedAt = Math.max(item.lastUsedAt || now, observation.lastActivity);
    }
    if (observation?.sample !== undefined) item.sample = observation.sample;
    if (action === 'forget') { forgotten++; continue; }
    if (action === 'close') {
      try { if (await adapter.close(item, observation) === true) { closed++; continue; } } catch { /* Retain uncertainty. */ }
    }
    remaining.push(item);
  }
  return { remaining, closed, forgotten };
}
