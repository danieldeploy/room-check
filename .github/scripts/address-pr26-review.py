from pathlib import Path

app_path = Path('assets/app.js')
app = app_path.read_text()

old = """        if (!name || !startDate || !endDate) {
            setStatus('Preencha o nome e as duas datas do intervalo', 'error');
            return;
        }
        createInterval.disabled = true;"""
new = """        if (!name || !startDate || !endDate) {
            setStatus('Preencha o nome e as duas datas do intervalo', 'error');
            return;
        }
        if (!(await resolveDirtyTextBeforeContextChange())) {
            return;
        }
        createInterval.disabled = true;"""
if old not in app:
    raise SystemExit('create interval guard anchor missing')
app = app.replace(old, new, 1)

old = """        if (!confirmed) return;
        saveInterval.disabled = true;"""
new = """        if (!confirmed) return;
        const deletingActiveInterval = Number(intervalSelect.value || 0) === interval.id;
        if (deletingActiveInterval && !(await resolveDirtyTextBeforeContextChange())) {
            return;
        }
        saveInterval.disabled = true;"""
if old not in app:
    raise SystemExit('delete interval guard anchor missing')
app = app.replace(old, new, 1)

old = """            const deletedActiveInterval = Number(intervalSelect.value || 0) === interval.id;
            if (deletedActiveInterval) {
                intervalSelect.value = '';
                employeeSelect.value = '';
            }
            syncIntervalManager();
            if (deletedActiveInterval) await loadChecklist();"""
new = """            if (deletingActiveInterval) {
                intervalSelect.value = '';
                employeeSelect.value = '';
            }
            syncIntervalManager();
            if (deletingActiveInterval) await loadChecklist();"""
if old not in app:
    raise SystemExit('delete active interval block missing')
app = app.replace(old, new, 1)
app_path.write_text(app)

test_path = Path('tests/autosave-hardening.php')
test = test_path.read_text()
old = """assertAutosaveHardening(
    str_contains($source, 'row.textarea.dataset.problem = persistedByName.get(row.name)'),
    'persisted problem baseline advances only after server success'
);"""
new = """assertAutosaveHardening(
    str_contains($source, 'row.textarea.dataset.problem = persisted;')
        && str_contains($source, 'row.textarea.dataset.persistedText = persisted;'),
    'persisted problem baseline advances only after server success'
);"""
if old not in test:
    raise SystemExit('autosave contract assertion missing')
test_path.write_text(test.replace(old, new, 1))

contract_path = Path('tests/invalid-language-edit-preservation.php')
contract = contract_path.read_text()
anchor = "okp(str_contains($app, 'resolveDirtyTextBeforeContextChange'), 'context changes validate pending text first');\n"
addition = """okp(str_contains($app, 'if (!(await resolveDirtyTextBeforeContextChange()))'), 'programmatic interval creation guards pending text before changing context');
okp(str_contains($app, 'deletingActiveInterval && !(await resolveDirtyTextBeforeContextChange())'), 'deleting the active interval guards pending text before changing context');
"""
if anchor not in contract:
    raise SystemExit('invalid edit contract anchor missing')
contract_path.write_text(contract.replace(anchor, anchor + addition, 1))
