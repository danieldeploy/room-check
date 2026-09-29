import { randomUUID } from 'node:crypto';
import { BOOKING_AUTH_ORIGINS } from './booking-permissions.mjs';

// Observe the documented AWS render API; leave its normal rendering and callbacks
// intact. Site keys and callback references stay in memory, never in diagnostics.
export function installAwsWidgetObserver(stateKey, origins) {
  if (!origins.includes(location.origin) || window[stateKey]) return;
  let current = null, sequence = 0, sdkValue = window.AwsWafCaptcha;
  const documentId = crypto.randomUUID();
  const loadedBeforeObserver = typeof sdkValue?.renderCaptcha === 'function';
  const originalGlobal = Object.getOwnPropertyDescriptor(window, 'AwsWafCaptcha');
  const restores = [];
  const read = (allowPuzzleTimeout = false) => {
    if (!current || current.used && !(allowPuzzleTimeout && current.endReason === 'widget_expired')
        || !current.container.isConnected
        || !current.container.getClientRects().length || current.url !== location.href) return null;
    const scripts = [...new Set([...document.scripts].map(script => script.src))];
    const matchingScripts = scripts.filter(src => {
      try {
        const url = new URL(src);
        return /^[a-f0-9]+\.edge\.captcha-sdk\.awswaf\.com$/i.test(url.hostname)
          && url.pathname === '/' + url.hostname.split('.')[0] + '/jsapi.js';
      } catch { return false; }
    });
    return { wafType: 'widget', websiteKey: current.apiKey,
      jsapiScript: matchingScripts.length === 1 ? matchingScripts[0] : undefined, widgetId: current.id };
  };
  const storeToken = (id, token, allowPuzzleTimeout = false) => {
    const snapshot = read(allowPuzzleTimeout);
    if (!snapshot || snapshot.widgetId !== id || typeof token !== 'string' || !token.length
        || token.length > 16384 || /[\s;,\u0000-\u001f\u007f]/.test(token)) return false;
    // The cookie is host-only. Never widen it to parent domains or change other cookies.
    document.cookie = 'aws-waf-token=' + token + '; Path=/; Secure; SameSite=Lax';
    const stored = document.cookie.split(';').map(value => value.trim())
      .filter(value => value.startsWith('aws-waf-token='));
    if (stored.length !== 1 || stored[0] !== 'aws-waf-token=' + token)
      throw new Error('widget token cookie unavailable');
    current.used = true;
    current.endReason = 'widget_completed';
    return true;
  };
  const wrap = sdk => {
    if (!sdk || typeof sdk.renderCaptcha !== 'function'
        || restores.some(item => item.sdk === sdk)) return;
    const original = sdk.renderCaptcha;
    const descriptor = Object.getOwnPropertyDescriptor(sdk, 'renderCaptcha');
    const wrapped = function(container, configuration) {
      current = null;
      if (!(container instanceof Element) || !configuration
          || typeof configuration.apiKey !== 'string' || !configuration.apiKey.length
          || configuration.apiKey.length > 16384 || typeof configuration.onSuccess !== 'function')
        return Reflect.apply(original, this, arguments);
      const entry = { container, apiKey: configuration.apiKey, url: location.href,
        id: stateKey + ':' + documentId + ':' + ++sequence, used: false, onSuccess: configuration.onSuccess,
        configuration };
      current = entry;
      const config = { ...configuration };
      for (const name of ['onSuccess', 'onPuzzleTimeout', 'onError']) {
        const callback = configuration[name];
        config[name] = function(...args) {
          entry.used = true;
          entry.endReason = name === 'onSuccess' ? 'widget_completed'
            : name === 'onPuzzleTimeout' ? 'widget_expired' : 'widget_error';
          if (name === 'onError' && ['internal_error', 'network_error', 'token_error', 'client_error'].includes(args[0]?.kind))
            entry.endReason = 'widget_' + args[0].kind;
          if (typeof callback === 'function') return Reflect.apply(callback, this, args);
        };
      }
      try { return Reflect.apply(original, this, [container, config]); }
      catch (error) { entry.used = true; entry.endReason = 'widget_error'; throw error; }
    };
    try {
      sdk.renderCaptcha = wrapped;
      if (sdk.renderCaptcha === wrapped) restores.push({ sdk, original, descriptor, wrapped });
    } catch { /* An immutable SDK is unsupported; don't alter its descriptors. */ }
  };
  wrap(sdkValue);
  const getter = () => sdkValue;
  const setter = value => { sdkValue = value; wrap(value); };
  // Do not replace an existing accessor or a non-configurable global.
  const ownsGlobal = !originalGlobal || originalGlobal.configurable
    && !originalGlobal.get && !originalGlobal.set;
  if (ownsGlobal) Object.defineProperty(window, 'AwsWafCaptcha', {
    configurable: true, enumerable: originalGlobal?.enumerable ?? true, get: getter, set: setter,
  });
  Object.defineProperty(window, stateKey, { configurable: true, value: {
    read: () => read(),
    invalidReason(id) {
      if (!current) return 'observer_missing';
      if (current.id !== id) return 'widget_replaced';
      if (current.used) return current.endReason || 'widget_completed';
      if (current.url !== location.href) return 'page_changed';
      if (!current.container.isConnected) return 'widget_removed';
      if (!current.container.getClientRects().length) return 'widget_hidden';
      return 'challenge_changed';
    },
    canRestart: () => !current && loadedBeforeObserver && typeof window.AwsWafCaptcha?.renderCaptcha === 'function',
    storeAfterPuzzleTimeout(id, token) {
      // A newly returned provider token is not the local puzzle's answer. Let
      // the server validate it after a GET, without calling an expired callback.
      if (current?.endReason !== 'widget_expired') return false;
      return storeToken(id, token, true);
    },
    complete(id, token) {
      const entry = current;
      // AWS normally updates this cookie before onSuccess. A provider token must
      // also reach the next protected request, not merely dismiss the widget UI.
      if (!storeToken(id, token)) return false;
      // Use the callback registered by the site through the public AWS contract.
      // No private callback guessing or credential POST replay.
      Reflect.apply(entry.onSuccess, entry.configuration, [token]);
      return true;
    },
    dispose() {
      current = null;
      for (const item of restores) {
        if (item.sdk.renderCaptcha !== item.wrapped) continue;
        if (item.descriptor) Object.defineProperty(item.sdk, 'renderCaptcha', item.descriptor);
        else delete item.sdk.renderCaptcha;
      }
      const descriptor = Object.getOwnPropertyDescriptor(window, 'AwsWafCaptcha');
      if (ownsGlobal && descriptor?.get === getter && descriptor?.set === setter) {
        if (originalGlobal) Object.defineProperty(window, 'AwsWafCaptcha', { ...originalGlobal, value: sdkValue });
        else if (sdkValue !== undefined) Object.defineProperty(window, 'AwsWafCaptcha', {
          configurable: true, enumerable: true, writable: true, value: sdkValue,
        });
        else delete window.AwsWafCaptcha;
      }
      delete window[stateKey];
    },
  } });
}

export function createAwsWidgetObserver() {
  const stateKey = '__hubAwsWidget_' + randomUUID().replaceAll('-', '');
  const pages = new Map();
  return {
    async prepare(page) {
      if (pages.has(page)) return;
      const script = await page.evaluateOnNewDocument(installAwsWidgetObserver, stateKey, BOOKING_AUTH_ORIGINS);
      pages.set(page, script.identifier);
      await page.evaluate(installAwsWidgetObserver, stateKey, BOOKING_AUTH_ORIGINS);
    },
    async read(page) {
      if (!pages.has(page)) return null;
      return page.evaluate(key => window[key]?.read() ?? null, stateKey);
    },
    async canRestart(page) {
      if (!pages.has(page)) return false;
      return page.evaluate(key => window[key]?.canRestart() === true, stateKey);
    },
    async invalidReason(page, id) {
      if (!pages.has(page)) return 'observer_missing';
      return page.evaluate((key, widgetId) => window[key]?.invalidReason(widgetId) ?? 'observer_missing', stateKey, id);
    },
    async waitForRender(page) {
      if (!pages.has(page)) return;
      // SPA widgets may render after the document's load event.
      await page.waitForFunction(key => !!window[key]?.read(), { timeout: 5000 }, stateKey).catch(() => {});
    },
    async complete(page, id, token) {
      return page.evaluate((key, widgetId, solution) => window[key]?.complete(widgetId, solution) === true,
        stateKey, id, token);
    },
    async storeAfterPuzzleTimeout(page, id, token) {
      return page.evaluate((key, widgetId, solution) => window[key]?.storeAfterPuzzleTimeout(widgetId, solution) === true,
        stateKey, id, token);
    },
    async release() {
      for (const [page, identifier] of pages) {
        await page.removeScriptToEvaluateOnNewDocument(identifier).catch(() => {});
        await page.evaluate(key => window[key]?.dispose(), stateKey).catch(() => {});
      }
      pages.clear();
    },
  };
}
