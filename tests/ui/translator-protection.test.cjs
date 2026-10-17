const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { JSDOM } = require('jsdom');

const translatorSource = readFileSync(join(__dirname, '../../src/I18n/Translator.php'), 'utf8');
const runtimeMatch = translatorSource.match(/\$script = <<<'HTML'\r?\n([\s\S]*?)\r?\nHTML;/);
if (!runtimeMatch) throw new Error('Translator browser runtime not found');

const literalPattern =
    '"[^"\\r\\n]*"|“[^”\\r\\n]*”|«[^»\\r\\n]*»|„[^“\\r\\n]*“|‘[^’\\r\\n]*’|‹[^›\\r\\n]*›|`[^`\\r\\n]*`'
    + "|(?<![\\p{L}\\p{N}])'[^'\\r\\n]+'(?![\\p{L}\\p{N}])"
    + '|https?:\\/\\/[^\\s<>"“”«»„‘’‹›`]+'
    + '|[\\p{L}\\p{N}._%+\\-]+@[\\p{L}\\p{N}.\\-]+\\.[\\p{L}]{2,}'
    + '|\\{\\{[^{}\\r\\n]+\\}\\}|\\{[A-Za-z_][A-Za-z0-9_.-]*\\}';
const dictionary = {
    Guardar: 'Save',
    para: 'for',
    welcomehostel: 'changed-host',
    room_id: 'changed-placeholder',
    'Apagar “{value}”?': 'Delete “{value}”?',
};
const runtime = runtimeMatch[1]
    .replace('__DICTIONARY__', JSON.stringify(dictionary))
    .replace('__TECHNICAL_LITERAL_PATTERN__', JSON.stringify(literalPattern));

function translatedPage() {
    const alerts = [];
    const confirmations = [];
    const html = `<!doctype html><html lang="pt"><head><title>Guardar "My2N"</title></head><body>
        <p id="copy">Guardar "My2N", “SIP”, «ZKTeco», „API“, ‘PIN’, 'RFID', ‹Cloudbeds› e \`V5.1\`.</p>
        <p id="template">Apagar “My2N”?</p>
        <p id="machine">Guardar https://check.welcomehostel.pt para info@welcomehostel.pt com {{room_id}}</p>
        <input id="field" placeholder="Guardar 'SIP'" title="Guardar &quot;PIN&quot;" aria-label="Guardar «RFID»">
        ${runtime}
    </body></html>`;
    const dom = new JSDOM(html, {
        runScripts: 'dangerously',
        beforeParse(window) {
            window.alert = message => alerts.push(message);
            window.confirm = message => { confirmations.push(message); return true; };
        },
    });
    return { dom, alerts, confirmations };
}

test('DOM and attributes translate around every supported technical literal', t => {
    const { dom } = translatedPage();
    t.after(() => dom.window.close());
    const document = dom.window.document;

    assert.equal(
        document.querySelector('#copy').textContent,
        'Save "My2N", “SIP”, «ZKTeco», „API“, ‘PIN’, \'RFID\', ‹Cloudbeds› e `V5.1`.'
    );
    assert.equal(document.querySelector('#template').textContent, 'Delete “My2N”?');
    assert.equal(
        document.querySelector('#machine').textContent,
        'Save https://check.welcomehostel.pt for info@welcomehostel.pt com {{room_id}}'
    );
    assert.equal(document.querySelector('#field').placeholder, "Save 'SIP'");
    assert.equal(document.querySelector('#field').title, 'Save "PIN"');
    assert.equal(document.querySelector('#field').getAttribute('aria-label'), 'Save «RFID»');
    assert.equal(document.title, 'Save "My2N"');
});

test('alerts and confirmations preserve quoted technical literals', t => {
    const { dom, alerts, confirmations } = translatedPage();
    t.after(() => dom.window.close());

    dom.window.alert('Guardar "ZKTeco"');
    dom.window.confirm('Apagar “My2N”?');
    assert.deepEqual(alerts, ['Save "ZKTeco"']);
    assert.deepEqual(confirmations, ['Delete “My2N”?']);
});
