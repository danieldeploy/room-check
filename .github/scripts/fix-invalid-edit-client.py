from pathlib import Path
p=Path('assets/app.js')
s=p.read_text()
replacements=[
    (r"/(\\p{L}[\\p{L}\\p{N}_-]*)/u", r"/(\p{L}[\p{L}\p{N}_-]*)/u"),
    (r"/\\p{L}[\\p{L}\\p{N}_-]*/gu", r"/\p{L}[\p{L}\p{N}_-]*/gu"),
]
for old,new in replacements:
    if old not in s: raise SystemExit('regex pattern missing: '+old)
    s=s.replace(old,new)
old="    let lastSavedChecklistFingerprint = '';\n"
new="    let lastSavedChecklistFingerprint = '';\n    let lastInvalidChecklistFingerprint = '';\n"
if old not in s: raise SystemExit('fingerprint state missing')
s=s.replace(old,new,1)
old="""            row.status.querySelectorAll('button').forEach((button) => { button.disabled = viewingAssignments || !canEdit; });
            autoGrow(row.textarea);
        });"""
new="""            row.status.querySelectorAll('button').forEach((button) => { button.disabled = viewingAssignments || !canEdit; });
            autoGrow(row.textarea);
            if (!row.invalidWords?.length) {
                row.textarea.dataset.persistedText = row.textarea.value;
            }
            if (row.validationOverlay && !row.validationOverlay.hidden) {
                row.validationOverlay.style.height = `${row.textarea.offsetHeight}px`;
            }
        });"""
if old not in s: raise SystemExit('updateAssignmentMode end missing')
s=s.replace(old,new,1)
old="""        if (isLoading || !canEdit || snapshot.fingerprint === lastSavedChecklistFingerprint) {
            return false;
        }"""
new="""        if (isLoading || !canEdit
            || snapshot.fingerprint === lastSavedChecklistFingerprint
            || snapshot.fingerprint === lastInvalidChecklistFingerprint) {
            return false;
        }"""
if old not in s: raise SystemExit('save checklist guard missing')
s=s.replace(old,new,1)
old="""                lastSavedChecklistFingerprint = snapshot.fingerprint;
                const persistedByName"""
new="""                lastSavedChecklistFingerprint = snapshot.fingerprint;
                lastInvalidChecklistFingerprint = '';
                const persistedByName"""
if old not in s: raise SystemExit('save success fp missing')
s=s.replace(old,new,1)
old="""                if (error.validation === true) {
                    const row = rows.find((candidate) => candidate.name === error.fieldKey);
                    if (row) renderLanguageValidation(row, error.invalidWords || []);
                }
                setStatus(error.message, 'error');"""
new="""                if (error.validation === true) {
                    lastInvalidChecklistFingerprint = snapshot.fingerprint;
                    const row = rows.find((candidate) => candidate.name === error.fieldKey);
                    if (row) renderLanguageValidation(row, error.invalidWords || []);
                }
                setStatus(error.message, 'error');"""
if old not in s: raise SystemExit('save error validation block missing')
s=s.replace(old,new,1)
old="""        const snapshot = checklistSnapshot();
        if (snapshot.fingerprint === lastSavedChecklistFingerprint) {
            return;
        }"""
new="""        const snapshot = checklistSnapshot();
        if (snapshot.fingerprint === lastSavedChecklistFingerprint
            || snapshot.fingerprint === lastInvalidChecklistFingerprint) {
            return;
        }"""
if old not in s: raise SystemExit('schedule snapshot guard missing')
s=s.replace(old,new,1)
old="""            autoGrow(textarea);
            const rowState = rows.find((candidate) => candidate.element === row);"""
new="""            autoGrow(textarea);
            lastInvalidChecklistFingerprint = '';
            const rowState = rows.find((candidate) => candidate.element === row);"""
if old not in s: raise SystemExit('input state anchor missing')
s=s.replace(old,new,1)
p.write_text(s)
