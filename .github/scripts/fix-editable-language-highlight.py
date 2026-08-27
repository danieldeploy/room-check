from pathlib import Path

js_path = Path('assets/validation-feedback.js')
js = js_path.read_text()

old = """        Object.assign(layer.style, {
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
        textarea.dataset.languageOriginalColor = textarea.style.color || '';
        textarea.dataset.languageOriginalBackground = textarea.style.backgroundColor || '';
        textarea.style.color = 'transparent';
        textarea.style.backgroundColor = 'transparent';
        textarea.style.caretColor = computed.color;
        textarea.style.position = 'relative';
        textarea.style.zIndex = '2';
"""
new = """        Object.assign(layer.style, {
            position: 'absolute', left: '0', top: '0',
            width: '100%', height: `${textarea.offsetHeight}px`,
            padding: computed.padding, borderWidth: computed.borderWidth, borderStyle: 'solid',
            borderColor: 'transparent', borderRadius: computed.borderRadius,
            boxSizing: computed.boxSizing, font: computed.font,
            lineHeight: computed.lineHeight, letterSpacing: computed.letterSpacing,
            textAlign: computed.textAlign, textIndent: computed.textIndent, wordSpacing: computed.wordSpacing,
            color: 'transparent', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', overflow: 'hidden',
            pointerEvents: 'none', zIndex: '3',
        });
"""
if old not in js:
    raise SystemExit('render highlight transparency block not found')
js = js.replace(old, new, 1)
js_path.write_text(js)

test_path = Path('tests/invalid-edit-ux.php')
test = test_path.read_text()
anchor = "assertInvalidEditUx(str_contains($feedback, 'delete textarea.dataset.languageNeedsValidation'), 'pending validation marker has explicit success/cancel clear paths');\n"
addition = """assertInvalidEditUx(
    !str_contains($feedback, \"textarea.style.color = 'transparent'\")
        && !str_contains($feedback, \"textarea.style.backgroundColor = 'transparent'\")
        && str_contains($feedback, \"color: 'transparent'\")
        && str_contains($feedback, \"pointerEvents: 'none', zIndex: '3'\"),
    'invalid-language highlight never makes the textarea itself transparent or non-interactive'
);
"""
if anchor not in test:
    raise SystemExit('invalid edit UX test anchor missing')
test_path.write_text(test.replace(anchor, anchor + addition, 1))
