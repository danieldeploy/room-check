import csv
import html
import re
from datetime import datetime
from pathlib import Path


def extract_pin_from_notes(notes_text: str, regex: str) -> str | None:
    if not notes_text:
        return None
    m = re.search(regex, notes_text, re.IGNORECASE | re.MULTILINE)
    return m.group(1) if m else None


def _all_fieldnames(rows: list[dict]) -> list[str]:
    preferred = ['room', 'guest', 'reservation_id', 'pin_cloudbeds', 'pin_zkaccess', 'pin_zkaccess_raw', 'status', 'message']
    keys = []
    for r in rows:
        for k in r.keys():
            if k not in keys:
                keys.append(k)
    return preferred + [k for k in keys if k not in preferred]


def save_csv(root: Path, rows: list[dict], prefix: str) -> Path:
    reports = root / 'reports'
    reports.mkdir(exist_ok=True)
    path = reports / f'{prefix}_{datetime.now():%Y%m%d_%H%M%S}.csv'
    fieldnames = _all_fieldnames(rows) if rows else ['room', 'guest', 'reservation_id', 'pin_cloudbeds', 'pin_zkaccess', 'status', 'message']
    with path.open('w', encoding='utf-8-sig', newline='') as f:
        w = csv.DictWriter(f, fieldnames=fieldnames, extrasaction='ignore')
        w.writeheader()
        for r in rows:
            w.writerow(r)
    return path


def save_html_report(root: Path, rows: list[dict], prefix: str = 'final_report') -> Path:
    reports = root / 'reports'
    reports.mkdir(exist_ok=True)
    path = reports / f'{prefix}_{datetime.now():%Y%m%d_%H%M%S}.html'
    fields = _all_fieldnames(rows) if rows else ['room', 'guest', 'pin_cloudbeds', 'pin_zkaccess', 'status']
    def status_class(value: str) -> str:
        v = (value or '').lower()
        if v in ('igual', 'alterado', 'ok'):
            return 'ok'
        if 'dry_run' in v:
            return 'warn'
        return 'err'
    body_rows = []
    for r in rows:
        cells = []
        for f in fields:
            val = html.escape(str(r.get(f, '') or ''))
            if f == 'status':
                cells.append(f'<td class="{status_class(r.get(f, ""))}">{val}</td>')
            else:
                cells.append(f'<td>{val}</td>')
        body_rows.append('<tr>' + ''.join(cells) + '</tr>')
    doc = f'''<!doctype html>
<html lang="pt"><head><meta charset="utf-8"><title>ZKCloudbedsAuto Report</title>
<style>
body{{font-family:Arial,sans-serif;margin:24px;background:#f7f7f7;color:#222}}
h1{{font-size:22px}} table{{border-collapse:collapse;width:100%;background:white}}
th,td{{border:1px solid #ddd;padding:8px;text-align:left;font-size:13px}}
th{{background:#222;color:white}} .ok{{color:#087a2a;font-weight:bold}} .warn{{color:#a36200;font-weight:bold}} .err{{color:#b00020;font-weight:bold}}
</style></head><body>
<h1>ZKCloudbedsAuto Report</h1><p>Gerado em {datetime.now():%Y-%m-%d %H:%M:%S}</p>
<table><thead><tr>{''.join(f'<th>{html.escape(f)}</th>' for f in fields)}</tr></thead><tbody>{''.join(body_rows)}</tbody></table>
</body></html>'''
    path.write_text(doc, encoding='utf-8')
    return path


def screenshot(page, root: Path, name: str, logger=None):
    path = root / 'screenshots' / f'{datetime.now():%Y%m%d_%H%M%S}_{name}.png'
    path.parent.mkdir(exist_ok=True)
    try:
        page.screenshot(path=str(path), full_page=True)
        if logger:
            logger.log(f'Screenshot guardado: {path}')
    except Exception as e:
        if logger:
            logger.log(f'Falha ao guardar screenshot {name}: {e}')
    return path
