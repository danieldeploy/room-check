const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { JSDOM } = require('jsdom');
const source = readFileSync(require('node:path').join(__dirname, '../../assets/save-context.js'), 'utf8');
const key = 'room-check:save-context:v2';
const url = 'https://hub.example/admin/invoices.php?tab=settings&period=2026-08';
const markup = `<main><details id="advanced"><summary>Advanced</summary><p>Details</p></details>
    <form method="post" data-y="1400"><input type="hidden" name="action" value="notifications">
    <input name="password" type="password" value="never-store-this-password">
    <input type="hidden" name="csrf_token" value="never-store-this-token">
    <input name="template_name" value="never-store-field-values">
    <details id="inside"><summary>More</summary><input name="required_field" required></details>
    <button data-y="1800">Save</button></form></main>`;

function page(t, { html = markup, href = url, stored, y = 0, shift = 0, height = 800, user = '1', navigation = 'navigate', blocked = false, clock = false } = {}) {
    const dom = new JSDOM(html, { url: href, runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    t.after(() => w.close());
    const d = w.document;
    const frames = [];
    const scrolls = [];
    const timers = [];
    let time = 0;
    if (clock) w.setTimeout = (callback, delay) => { timers.push({ callback, at: time + delay }); };
    const advance = milliseconds => {
        time += milliseconds;
        for (const timer of timers.filter(timer => timer.at <= time)) {
            timers.splice(timers.indexOf(timer), 1);
            timer.callback();
        }
    };
    w.CSS = { escape: value => value.replace(/[^\w-]/g, '\\$&') };
    w.history.scrollRestoration = 'auto';
    w.performance.getEntriesByType = () => [{ type: navigation }];
    Object.defineProperty(d, 'readyState', { get: () => 'complete' });
    Object.defineProperty(w, 'innerHeight', { value: height });
    w.scrollY = y;
    w.scrollTo = ({ left, top }) => { w.scrollX = left; w.scrollY = top; scrolls.push(top); };
    w.requestAnimationFrame = fn => frames.push(fn);
    w.HTMLElement.prototype.getBoundingClientRect = function () { return { top: Number(this.dataset.y || 1000) + shift - w.scrollY }; };
    w.HTMLElement.prototype.getClientRects = function () { return this.closest('details:not([open])') ? [] : [{}]; };
    w.HTMLElement.prototype.scrollIntoView = function (options) { this.scrolled = options; };
    if (stored) w.sessionStorage.setItem(key, typeof stored === 'string' ? stored : JSON.stringify(stored));
    if (blocked) Object.defineProperty(w, 'sessionStorage', { get() { throw new Error('Storage denied'); } });
    const script = d.createElement('script');
    script.dataset.userId = user;
    script.dataset.dismissLabel = 'Close';
    Object.defineProperty(d, 'currentScript', { value: script });
    const start = () => w.eval(source);
    const flush = () => { while (frames.length) frames.shift()(); };
    const submit = (form = d.querySelector('form'), button = form.querySelector('button')) => {
        form.dispatchEvent(new w.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: button }));
    };
    const leave = () => w.dispatchEvent(new w.PageTransitionEvent('pagehide'));
    const click = (element, options = {}) => element.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true, ...options }));
    return { w, d, start, flush, submit, leave, click, advance, scrolls, stored: () => w.sessionStorage.getItem(key) };
}

function snapshot(t, options = {}) {
    const p = page(t, { y: 1300, ...options });
    p.start();
    p.d.querySelector('#advanced').open = true;
    p.submit();
    p.leave();
    return p.stored();
}

test('a native save keeps the button position, URL filters and open sections after layout changes', t => {
    const p = page(t, { stored: snapshot(t), shift: 90, html: markup + '<div data-save-feedback="success">Saved and translated.</div>' });
    p.start(); p.flush();
    assert.equal(p.w.scrollY, 1390);
    assert.equal(p.d.querySelector('#advanced').open, true);
    assert.equal(p.d.querySelector('#inside').open, false);
    assert.equal(p.w.location.href, url);
    assert.match(p.d.querySelector('.save-context-notice').textContent, /Saved and translated/);
    assert.equal(p.stored(), null);
});

test('mobile height changes keep the save control inside the new viewport', t => {
    const p = page(t, { stored: snapshot(t), height: 430 });
    p.start(); p.flush();
    assert.equal(p.d.querySelector('button').getBoundingClientRect().top, 350);
});

test('presentation snapshots never contain passwords, CSRF tokens or editable field values', t => {
    const stored = snapshot(t);
    assert.doesNotMatch(stored, /never-store|csrf_token|template_name|password/);
    assert.equal(JSON.parse(stored).form, 'action:notifications');
});

test('cancelled and async submissions do not leave snapshots on later navigation', t => {
    for (const target of ['form', 'document', 'window']) {
        const p = page(t);
        p.start();
        const element = target === 'form' ? p.d.querySelector('form') : target === 'document' ? p.d : p.w;
        element.addEventListener('submit', event => event.preventDefault());
        p.submit(); p.leave();
        assert.equal(p.stored(), null, target);
    }
});

test('a confirmed re-submission is captured after the first one was cancelled', t => {
    const p = page(t, { y: 1200 });
    p.start();
    p.d.querySelector('form').addEventListener('submit', event => event.preventDefault(), { once: true });
    p.submit(); p.submit(); p.leave();
    assert.equal(JSON.parse(p.stored()).y, 1200);
});

test('GET, other modules, logout, new tabs, external targets and explicit opt-out remain normal navigation', t => {
    for (const attributes of ['method="get"', 'method="post" action="../logout.php"', 'method="post" target="_blank"',
        'method="post" action="https://other.example/admin/invoices.php"', 'method="post" data-save-context="off"']) {
        const p = page(t, { html: markup.replace('method="post"', attributes) });
        p.start(); p.submit(); p.leave();
        assert.equal(p.stored(), null, attributes);
    }
});

test('submitter overrides for formmethod, formaction and formtarget are respected', t => {
    for (const attribute of ['formmethod="get"', 'formaction="other.php"', 'formtarget="_blank"']) {
        const p = page(t, { html: markup.replace('<button ', '<button ' + attribute + ' ') });
        p.start(); p.submit(); p.leave();
        assert.equal(p.stored(), null, attribute);
    }
});

test('switching tab, filter, edited record or module consumes the snapshot without restoring', t => {
    for (const href of [url.replace('settings', 'activity'), url.replace('2026-08', '2026-07'), url + '&edit=3', 'https://hub.example/index.php']) {
        const p = page(t, { stored: snapshot(t), href });
        p.start(); p.flush();
        assert.equal(p.scrolls.length, 0, href);
        assert.equal(p.stored(), null);
    }
});

test('invoice edit=0 and query ordering are equivalent, but real record changes are not', t => {
    const p = page(t, { stored: snapshot(t), href: 'https://hub.example/admin/invoices.php?period=2026-08&edit=0&tab=settings&portal=&account=0&property=&q=&state=' });
    p.start(); p.flush();
    assert.equal(p.w.scrollY, 1300);
});

test('declared same-page result redirect and server validation return both preserve context', t => {
    const href = 'https://hub.example/admin/my2n.php';
    const html = markup.replace('method="post"', 'method="post" data-save-context-return="my2n.php?credentials=saved"');
    const stored = snapshot(t, { href, html });
    for (const destination of [href, href + '?credentials=saved']) {
        const p = page(t, { href: destination, html, stored });
        p.start(); p.flush();
        assert.equal(p.w.scrollY, 1300);
    }
});

test('snapshot is rejected for another user, expired data, ordinary reloads and history navigation', t => {
    const stored = JSON.parse(snapshot(t));
    for (const extra of [{ user: '2' }, { navigation: 'reload' }, { navigation: 'back_forward' },
        { stored: { ...stored, at: Date.now() - 301000 } }, { stored: 'invalid json' }]) {
        const p = page(t, { stored, ...extra });
        p.start(); p.flush();
        assert.equal(p.scrolls.length, 0);
        assert.equal(p.stored(), null);
    }
});

test('clicking a navigation link clears a pending save', t => {
    const p = page(t, { html: markup + '<a href="other.php">Navigate</a>' });
    p.start(); p.submit();
    p.d.querySelector('a').dispatchEvent(new p.w.Event('click', { bubbles: true }));
    p.leave();
    assert.equal(p.stored(), null);
});

test('native validation reveals collapsed ancestors without persisting a submission', t => {
    const p = page(t);
    p.start();
    p.d.querySelector('[required]').dispatchEvent(new p.w.Event('invalid', { cancelable: true }));
    assert.equal(p.d.querySelector('#inside').open, true);
    p.leave();
    assert.equal(p.stored(), null);
});

test('server field errors win over success and focus the invalid field with minimal scrolling', t => {
    const html = markup.replace('name="required_field"', 'name="required_field" aria-invalid="true"')
        + '<p data-save-feedback="error">Invalid value</p><p data-save-feedback="success">Old success</p>';
    const p = page(t, { stored: snapshot(t), html });
    p.start(); p.flush();
    const field = p.d.querySelector('[aria-invalid]');
    assert.equal(p.d.activeElement, field);
    assert.equal(field.scrolled.block, 'nearest');
    assert.equal(p.d.querySelector('.save-context-notice').getAttribute('role'), 'alert');
    assert.doesNotMatch(p.d.querySelector('.save-context-notice').textContent, /Old success/);
});

test('generic server errors remain visible and dismissible at the saved position', t => {
    const p = page(t, { stored: snapshot(t), html: markup + '<p data-save-feedback="error">Try again later</p>' });
    p.start(); p.flush();
    assert.equal(p.w.scrollY, 1300);
    const notice = p.d.querySelector('.save-context-notice');
    assert.match(notice.textContent, /Try again later/);
    notice.querySelector('button').click();
    assert.equal(p.d.querySelector('.save-context-notice'), null);
});

test('a response with no marked server outcome never invents success', t => {
    const p = page(t, { stored: snapshot(t), html: markup + '<p class="success">Connected service</p>' });
    p.start(); p.flush();
    assert.equal(p.d.querySelector('.save-context-notice'), null);
});

test('missing form/anchor falls back to coordinates', t => {
    const p = page(t, { stored: snapshot(t), html: '<main><p>Empty result</p></main>' });
    p.start(); p.flush();
    assert.equal(p.w.scrollY, 1300);
});

test('user scroll or touch cancels delayed restoration and restores normal browser history behavior', async t => {
    const p = page(t, { stored: snapshot(t) });
    p.start(); p.flush();
    const count = p.scrolls.length;
    p.w.dispatchEvent(new p.w.Event('touchstart'));
    await new Promise(resolve => setImmediate(resolve));
    p.flush();
    assert.equal(p.scrolls.length, count);
    assert.equal(p.w.history.scrollRestoration, 'auto');
});

test('storage unavailable never blocks a native save or page load', t => {
    const p = page(t, { blocked: true });
    assert.doesNotThrow(() => { p.start(); p.submit(); p.leave(); });
});

test('an existing validation modal retains control of focus and scroll', t => {
    const p = page(t, { stored: snapshot(t), html: markup + '<div role="alertdialog"><button>Correct</button></div>' });
    p.start(); p.flush();
    assert.equal(p.scrolls.length, 0);
    assert.equal(p.w.history.scrollRestoration, 'auto');
});

test('same-action repeated record forms restore to the correct record after rows are inserted', t => {
    const row = id => `<form method="post" data-y="1200"><input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" value="${id}"><button data-y="1500">Save</button></form>`;
    const p = page(t, { html: row(1) + row(2), y: 1000 });
    p.start(); p.submit(p.d.forms[1]); p.leave();
    const next = page(t, { html: row(3) + row(1) + row(2), stored: p.stored(), shift: 100 });
    next.start(); next.flush();
    // The old selector now identifies user 1. Use user 2's form as fallback.
    assert.equal(next.w.scrollY, 1100);
});

test('success notice lasts two seconds without a close button or a focus change; errors persist', t => {
    for (const kind of ['success', 'error']) {
        const p = page(t, { stored: snapshot(t), clock: true, html: markup + `<p data-save-feedback="${kind}">Server result</p>` });
        p.start(); p.flush();
        const notice = p.d.querySelector('.save-context-notice');
        assert.equal(Boolean(notice.querySelector('button')), kind === 'error');
        assert.equal(p.d.activeElement, p.d.body);
        p.advance(1999);
        assert.equal(notice.isConnected, true);
        p.advance(1);
        assert.equal(notice.isConnected, kind === 'error');
    }
});

const filterMarkup = `<form method="get" id="filters" data-save-context="filter" data-save-context-target="#results" data-y="1000">
    <input type="hidden" name="tab" value="documents"><input name="q" value="Air & Sea">
    <input name="disabled" disabled value="excluded"><input type="checkbox" name="unchecked" value="excluded">
    <select name="portal"><option value="booking" selected>Booking</option></select>
    <button name="sort" value="date" data-y="1200">Filter</button></form>
    <section id="results" data-y="1500"><details id="record"><summary>Record</summary></details></section>`;
const filteredUrl = 'https://hub.example/admin/invoices.php?tab=documents&q=Air+%26+Sea&portal=booking&sort=date';

test('GET filters match the actual successful controls and keep visible results in place', t => {
    const p = page(t, { html: filterMarkup, y: 900 });
    p.start(); p.d.querySelector('#record').open = true;
    p.submit(); p.leave();
    const state = JSON.parse(p.stored());
    assert.doesNotMatch(state.to, /excluded|disabled|unchecked/);
    const next = page(t, { html: filterMarkup, href: filteredUrl, stored: state, shift: 100 });
    next.start(); next.flush();
    assert.equal(next.d.querySelector('#results').getBoundingClientRect().top, 600);
    assert.equal(next.d.querySelector('#record').open, false, 'New result rows use their server state');
    assert.equal(next.d.querySelector('.save-context-notice'), null);
});

test('filters reveal an off-screen result area, including an empty result, and reject unexpected redirects', t => {
    const p = page(t, { html: filterMarkup, y: 400 });
    p.start(); p.submit(); p.leave();
    const html = filterMarkup.replace('<details id="record"><summary>Record</summary></details>', '<p>No matches</p>');
    const next = page(t, { html, href: filteredUrl, stored: p.stored(), height: 430 });
    next.start(); next.flush();
    assert.equal(next.d.querySelector('#results').getBoundingClientRect().top, 16);
    const wrong = page(t, { html, href: filteredUrl + '&edit=3', stored: p.stored() });
    wrong.start(); wrong.flush();
    assert.equal(wrong.scrolls.length, 0);
});

test('selectors submitted without a button preserve their section and selected value', t => {
    const html = '<details id="selector"><summary>Edit list</summary><form method="get" id="select-list" data-save-context="keep" data-y="1000"><select name="list_id"><option value="2" selected>List 2</option></select></form></details>';
    const p = page(t, { html, href: 'https://hub.example/item-lists.php', y: 650 });
    p.start(); p.d.querySelector('details').open = true; p.submit(); p.leave();
    const next = page(t, { html, href: 'https://hub.example/item-lists.php?list_id=2', stored: p.stored(), shift: 80 });
    next.start(); next.flush();
    assert.equal(next.d.querySelector('details').open, true);
    assert.equal(next.d.querySelector('form').getBoundingClientRect().top, 350);
    assert.equal(next.d.querySelector('select').value, '2');
});

test('annotated GET password forms and cancelled filters never produce snapshots', t => {
    for (const password of [false, true]) {
        const html = filterMarkup.replace('</form>', password ? '<input type="password" name="password" value="secret"></form>' : '</form>');
        const p = page(t, { html });
        p.start();
        if (!password) p.w.addEventListener('submit', event => event.preventDefault());
        p.submit(); p.leave();
        assert.equal(p.stored(), null);
    }
});

test('pagination starts the relevant results while calendar arrows keep the calendar position', t => {
    for (const mode of ['page', 'keep']) {
        const html = `<section id="results" data-y="1100">Results</section><a href="?page=2" data-save-context="${mode}" data-save-context-target="#results" data-y="3000">Next</a>`;
        const p = page(t, { html, y: mode === 'page' ? 2600 : 800 });
        p.start(); p.click(p.d.querySelector('a')); p.leave();
        const next = page(t, { html, href: 'https://hub.example/admin/invoices.php?page=2', stored: p.stored(), shift: 150 });
        next.start(); next.flush();
        assert.equal(next.d.querySelector('#results').getBoundingClientRect().top, mode === 'page' ? 16 : 300);
    }
});

test('modified, downloaded, cancelled, new-tab and cross-module links keep native navigation', t => {
    for (const kind of ['ctrl', 'meta', 'shift', 'alt', 'middle', 'cancel', 'download', 'target', 'other']) {
        const html = `<a href="${kind === 'other' ? '../tasks.php' : '?page=2'}" data-save-context="page" ${kind === 'download' ? 'download' : ''} ${kind === 'target' ? 'target="_blank"' : ''}>Next</a>`;
        const p = page(t, { html });
        p.start();
        if (kind === 'cancel') p.w.addEventListener('click', event => event.preventDefault());
        p.click(p.d.querySelector('a'), { ctrlKey: kind === 'ctrl', metaKey: kind === 'meta', shiftKey: kind === 'shift', altKey: kind === 'alt', button: kind === 'middle' ? 1 : 0 });
        p.leave();
        assert.equal(p.stored(), null, kind);
    }
});

test('opening or closing a section anchors its heading after sibling layout changes', t => {
    const html = '<details id="earlier" open><summary>Earlier</summary></details><details id="chosen"><summary data-y="1200">Chosen</summary></details>';
    const p = page(t, { html, y: 800 });
    p.start();
    const summary = p.d.querySelector('#chosen summary');
    p.click(summary);
    p.d.querySelector('#earlier').open = false;
    summary.dataset.y = '900'; // A previous accordion section collapsed.
    p.flush();
    assert.equal(summary.getBoundingClientRect().top, 400);
    assert.equal(p.d.querySelector('#chosen').open, true);
    p.click(summary); p.flush();
    assert.equal(summary.getBoundingClientRect().top, 400);
    assert.equal(p.d.querySelector('#chosen').open, false);
});

test('section correction respects user scrolling, validation dialogs and opt-outs', t => {
    for (const kind of ['scroll', 'modal', 'off']) {
        const p = page(t, { html: `<details ${kind === 'off' ? 'data-save-context="off"' : ''}><summary>Section</summary></details>` });
        p.start(); p.click(p.d.querySelector('summary'));
        if (kind === 'scroll') p.w.dispatchEvent(new p.w.Event('wheel'));
        if (kind === 'modal') p.d.body.insertAdjacentHTML('beforeend', '<dialog open>Validation</dialog>');
        p.flush();
        assert.equal(p.scrolls.length, 0, kind);
    }
});
