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
  const read = () => {
    if (!current || current.used || !current.container.isConnected
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
          if (typeof callback === 'function') return Reflect.apply(callback, this, args);
        };
      }
      try { return Reflect.apply(original, this, [container, config]); }
      catch (error) { entry.used = true; throw error; }
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
    read,
    canRestart: () => !current && loadedBeforeObserver && typeof window.AwsWafCaptcha?.renderCaptcha === 'function',
    complete(id, token) {
      const snapshot = read();
      if (!snapshot || snapshot.widgetId !== id || typeof token !== 'string' || !token.length) return false;
      const entry = current;
      entry.used = true;
      // Use the callback registered by the site through the public AWS contract.
      // No private callback guessing, cookie replacement, or credential POST replay.
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
    async waitForRender(page) {
      if (!pages.has(page)) return;
      // SPA widgets may render after the document's load event.
      await page.waitForFunction(key => !!window[key]?.read(), { timeout: 5000 }, stateKey).catch(() => {});
    },
    async complete(page, id, token) {
      return page.evaluate((key, widgetId, solution) => window[key]?.complete(widgetId, solution) === true,
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
