from pathlib import Path

js_path = Path('assets/validation-feedback.js')
js = js_path.read_text()
old = """        Object.assign(layer.style, {
            position: 'absolute', left: `${textarea.offsetLeft}px`, top: `${textarea.offsetTop}px`,
            width: `${textarea.offsetWidth}px`, minHeight: `${textarea.offsetHeight}px`,
            padding: computed.padding, borderWidth: computed.borderWidth, borderStyle: 'solid',
            borderColor: 'transparent', boxSizing: computed.boxSizing, font: computed.font,
            lineHeight: computed.lineHeight, letterSpacing: computed.letterSpacing,
            whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', pointerEvents: 'none', zIndex: '1',
        });
        textarea.parentElement.style.position = 'relative';
"""
new = """        Object.assign(layer.style, {
            position: 'absolute', left: '0', top: '0',
            width: '100%', height: `${textarea.offsetHeight}px`,
            padding: computed.padding, borderWidth: computed.borderWidth, borderStyle: 'solid',
            borderColor: 'transparent', borderRadius: computed.borderRadius,
            boxSizing: computed.boxSizing, font: computed.font,
            lineHeight: computed.lineHeight, letterSpacing: computed.letterSpacing,
            textAlign: computed.textAlign, textIndent: computed.textIndent, wordSpacing: computed.wordSpacing,
            whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', overflow: 'hidden',
            pointerEvents: 'none', zIndex: '1',
        });
"""
if old not in js:
    raise SystemExit('highlight positioning block not found')
js_path.write_text(js.replace(old, new, 1))

css_path = Path('assets/app.css')
css = css_path.read_text()
old = '.problem-field { min-width: 0; }'
new = '.problem-field { position: relative; min-width: 0; }'
if old not in css:
    raise SystemExit('problem-field rule not found')
css_path.write_text(css.replace(old, new, 1))

test_path = Path('tests/invalid-edit-ux.php')
test = test_path.read_text()
anchor = "assertInvalidEditUx(str_contains($css, '.language-decision-overlay') && str_contains($css, '.language-wrong-segment'), 'highlight and decision dialog styles are present');\n"
addition = """assertInvalidEditUx(str_contains($css, '.problem-field { position: relative; min-width: 0; }'), 'highlight layer is anchored to the textarea field');
assertInvalidEditUx(
    str_contains($feedback, \"position: 'absolute', left: '0', top: '0'\")
        && str_contains($feedback, \"width: '100%', height:\")
        && str_contains($feedback, 'textarea.offsetHeight')
        && !str_contains($feedback, 'textarea.offsetLeft')
        && !str_contains($feedback, 'textarea.offsetTop'),
    'highlight overlay starts at the textarea origin instead of reusing pre-anchor offsets'
);
"""
if anchor not in test:
    raise SystemExit('invalid edit UX test anchor not found')
test_path.write_text(test.replace(anchor, anchor + addition, 1))
