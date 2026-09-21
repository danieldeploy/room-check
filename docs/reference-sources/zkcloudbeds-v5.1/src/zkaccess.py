from pathlib import Path
import json
import re
import time
from urllib.parse import quote, urlencode
from .utils import screenshot


ZK_PASSWORD_DECODE_TABLE = {
    'AF': '5', 'BF': '4', 'CF': '7', 'DF': '6', 'EF': '1',
    'FF': '0', 'GF': '3', 'HF': '2', 'MF': '9', 'NF': '8',
}


def decode_zk_password(value: str) -> str:
    if value is None:
        return ''
    raw = str(value).strip()
    if not raw:
        return ''
    if raw.isdigit():
        return raw
    code = raw.upper()
    if len(code) % 2 != 0:
        return raw
    out = []
    for i in range(0, len(code), 2):
        pair = code[i:i + 2]
        digit = ZK_PASSWORD_DECODE_TABLE.get(pair)
        if digit is None:
            return raw
        out.append(digit)
    return ''.join(out)


class ZKAccessClient:
    def __init__(self, page, cfg: dict, root: Path, logger):
        self.page = page
        self.cfg = cfg
        self.root = root
        self.log = logger.log
        self.employee_cache: dict[str, dict] = {}
        self.logged_in = False
        self.current_employee: dict | None = None

    def open(self):
        self.log(f"A abrir ZKAccess: {self.cfg['zkaccess_url']}")
        self.page.goto(self.cfg['zkaccess_url'], wait_until='domcontentloaded', timeout=60000)
        self.page.wait_for_timeout(700)

    def is_login_visible(self) -> bool:
        for sel in ['#id_login', "input[name='username']", "input[name='password']"]:
            try:
                if self.page.locator(sel).first.is_visible(timeout=1000):
                    return True
            except Exception:
                pass
        return False

    def login(self):
        if self.logged_in:
            return True
        self.open()
        if not self.is_login_visible():
            self.log('ZKAccess: já autenticado.')
            self.logged_in = True
            return True
        self.log('ZKAccess: formulário de login detetado ou sessão expirada.')
        screenshot(self.page, self.root, 'zkaccess_login_page', self)
        for sel in ["input[name='username']", '#username', "input[type='text']"]:
            try:
                self.page.locator(sel).first.fill(self.cfg['zkaccess_username'], timeout=3000)
                self.log(f'ZKAccess: username preenchido com {sel}')
                break
            except Exception:
                pass
        for sel in ["input[name='password']", '#password', "input[type='password']"]:
            try:
                self.page.locator(sel).first.fill(self.cfg['zkaccess_password'], timeout=3000)
                self.log(f'ZKAccess: password preenchida com {sel}')
                break
            except Exception:
                pass
        try:
            self.page.locator('#id_login').click(timeout=5000)
            self.log('ZKAccess: cliquei em Login com seletor: #id_login')
        except Exception:
            self.page.keyboard.press('Enter')
            self.log('ZKAccess: pressionei Enter como fallback de login')
        self.page.wait_for_timeout(2500)
        screenshot(self.page, self.root, 'zkaccess_after_login_click', self)
        if self.is_login_visible():
            raise RuntimeError('ZKAccess: login não confirmado')
        self.logged_in = True
        self.log('ZKAccess: login confirmado.')
        return True

    def open_personnel(self):
        self.login()
        for sel in ['text=Personnel', 'a:has-text("Personnel")', 'span:has-text("Personnel")']:
            try:
                self.page.locator(sel).first.click(timeout=8000)
                self.log(f'ZKAccess: cliquei em Personnel com seletor: {sel}')
                self.page.wait_for_timeout(1200)
                return
            except Exception:
                pass
        raise RuntimeError('ZKAccess: não consegui abrir Personnel')

    def _employee_search_request(self, search_value: str, limit: int = 100) -> str:
        base = self.cfg['zkaccess_url'].rstrip('/')
        ts = int(time.time() * 1000)
        query_name = quote(search_value)
        url = (
            f"{base}/data/personnel/Employee/"
            f"?o=PIN,DeptID__code,lastname,Card,EName,acc_startdate,acc_enddate"
            f"&stamp={ts}&EName__icontains={query_name}&l={limit}"
        )
        try:
            resp = self.page.request.post(url, timeout=30000)
            return resp.text()
        except Exception as exc:
            self.log(f'ZKAccess: page.request falhou ({exc}); fallback fetch no browser')
            return self.page.evaluate(
                """async (url) => {
                    const r = await fetch(url, {method: 'POST', credentials: 'same-origin'});
                    return await r.text();
                }""",
                url,
            )

    def _parse_employee_rows(self, text: str) -> list[dict]:
        m = re.search(r"data:\s*\[(.*?)\]\s*,\s*record_count", text, re.S)
        if not m:
            self.log('ZKAccess: resposta API não contém data[] reconhecível')
            return []
        data_block = '[' + m.group(1).strip() + ']'
        try:
            rows = json.loads(data_block)
        except Exception as exc:
            self.log(f'ZKAccess: não consegui interpretar data[] da API: {exc}')
            return []
        out = []
        for row in rows:
            if not isinstance(row, list) or len(row) < 3:
                continue
            out.append({
                'id': str(row[0]).strip(),
                'personnel_no': str(row[1]).strip(),
                'first_name': str(row[2]).strip(),
                'last_name': str(row[3]).strip() if len(row) > 3 else '',
                'row': row,
            })
        return out

    def build_employee_cache(self, first_names: list[str] | None = None):
        """Fast batch lookup: one backend search for 'Room', then exact matching in memory."""
        self.login()
        if self.employee_cache:
            return self.employee_cache
        term = self.cfg.get('zkaccess_room_search_term', 'Room')
        if not self.cfg.get('zkaccess_batch_lookup', True):
            term = first_names[0] if first_names else term
        self.log(f'ZKAccess: pesquisa em lote Employee por First Name contendo {term!r}')
        rows = self._parse_employee_rows(self._employee_search_request(term, limit=200))
        wanted = {x.lower() for x in (first_names or []) if x}
        cache: dict[str, dict] = {}
        duplicates: dict[str, list[dict]] = {}
        for r in rows:
            name = r['first_name'].strip()
            if wanted and name.lower() not in wanted:
                continue
            if not name:
                continue
            if name in cache:
                duplicates.setdefault(name, [cache[name]]).append(r)
            else:
                cache[name] = r
        if duplicates:
            detail = ', '.join(f'{k}({len(v)})' for k, v in duplicates.items())
            raise RuntimeError(f'ZKAccess: funcionários duplicados encontrados: {detail}. Não vou escolher automaticamente.')
        self.employee_cache = cache
        self.log(f'ZKAccess: cache de funcionários criado com {len(cache)} item(ns).')
        for name, r in sorted(cache.items()):
            self.log(f"  {name} -> EmployeeID={r['id']} PersonnelNo={r['personnel_no']}")
        return self.employee_cache

    def _search_employee_api(self, first_name: str) -> dict:
        self.login()
        if not self.employee_cache:
            self.build_employee_cache([first_name])
        exact = self.employee_cache.get(first_name)
        if exact:
            return exact
        # fallback with exact individual query
        self.log(f'ZKAccess: fallback pesquisa individual para {first_name}')
        rows = self._parse_employee_rows(self._employee_search_request(first_name, limit=20))
        matches = [r for r in rows if r['first_name'].lower() == first_name.lower()]
        self.log(f'ZKAccess: API encontrou {len(matches)} match(es) exatos para {first_name}')
        if len(matches) == 1:
            return matches[0]
        if len(matches) > 1:
            raise RuntimeError(f'ZKAccess: existem vários funcionários com First Name={first_name}; não vou escolher automaticamente')
        return {}

    def _open_employee_by_id(self, emp_id: str, first_name: str) -> bool:
        base = self.cfg['zkaccess_url'].rstrip('/')
        ts = int(time.time() * 1000)
        url = f"{base}/data/personnel/Employee/{emp_id}/?virtual_app=personnel&stamp={ts}&_={ts}"
        self.log(f'ZKAccess: a abrir EmployeeID={emp_id} diretamente')
        self.page.goto(url, wait_until='domcontentloaded', timeout=60000)
        ok = self._wait_employee_form_for(first_name, timeout_ms=10000)
        if ok:
            self.current_employee = {'id': str(emp_id), 'first_name': first_name}
        return ok

    def _wait_employee_form_for(self, first_name: str, timeout_ms: int = 10000) -> bool:
        for _ in range(max(1, timeout_ms // 400)):
            try:
                if self.page.locator('#id_Password, input[name="Password"]').first.is_visible(timeout=400):
                    for sel in ['#id_EName', "input[name='EName']", '#id_FirstName', "input[name='FirstName']", "input[name='firstName']", "input[id*='FirstName']"]:
                        try:
                            v = self.page.locator(sel).first.input_value(timeout=500).strip()
                            if v:
                                if v.lower() == first_name.lower():
                                    self.log(f'ZKAccess: formulário confirmado para First Name={v}')
                                    return True
                                self.log(f'ZKAccess: formulário aberto mas First Name={v}, esperado={first_name}')
                                return False
                        except Exception:
                            pass
                    try:
                        body = self.page.locator('body').inner_text(timeout=700)
                        if first_name.lower() in body.lower():
                            self.log(f'ZKAccess: formulário aberto e texto contém {first_name}')
                            return True
                    except Exception:
                        pass
                    self.log('ZKAccess: formulário Employee aberto; First Name não visível para confirmar')
                    return True
            except Exception:
                pass
            self.page.wait_for_timeout(400)
        return False

    def find_person_by_first_name(self, first_name: str):
        employee = self._search_employee_api(first_name)
        if employee:
            if self._open_employee_by_id(employee['id'], first_name):
                return
            screenshot(self.page, self.root, f'zkaccess_employee_api_open_failed_{first_name}', self)
            raise RuntimeError(f"ZKAccess: API encontrou {first_name}, mas não confirmei EmployeeID={employee['id']}")
        raise RuntimeError(f'ZKAccess: não encontrei funcionário com First Name={first_name}')

    def read_password(self) -> dict:
        for sel in ["#id_Password", "input[name='Password']", "input[name='password']", "input[id*='Password']", "input[id*='password']"]:
            try:
                loc = self.page.locator(sel).first
                raw = loc.input_value(timeout=3500).strip()
                decoded = decode_zk_password(raw)
                self.log(f'ZKAccess: Password atual lida com {sel}: raw={raw} decoded={decoded}')
                return {'raw': raw, 'decoded': decoded, 'selector': sel}
            except Exception:
                pass
        return {'raw': '', 'decoded': '', 'selector': ''}


    def _collect_employee_form_pairs(self, new_pin: str) -> list[tuple[str, str]]:
        """Collects the currently open Employee form exactly as the browser would submit it.
        Keeps duplicate fields such as level=... and checkbox hidden/value pairs.
        Replaces Password with the new plain PIN.
        """
        pairs = self.page.evaluate(
            """(newPin) => {
                const password = document.querySelector('#id_Password, input[name="Password"]');
                if (!password) return [];
                password.value = String(newPin);
                password.setAttribute('value', String(newPin));
                password.dispatchEvent(new Event('input', {bubbles: true}));
                password.dispatchEvent(new Event('change', {bubbles: true}));

                const form = password.closest('form') || document.querySelector('form');
                if (!form) return [];
                const fd = new FormData(form);
                // Ensure Password is present with the plain PIN, even if the field is disabled/handled specially.
                fd.delete('Password');
                fd.append('Password', String(newPin));
                return Array.from(fd.entries()).map(([k, v]) => [String(k), String(v)]);
            }""",
            str(new_pin),
        )
        out: list[tuple[str, str]] = []
        for item in pairs or []:
            if isinstance(item, (list, tuple)) and len(item) == 2:
                out.append((str(item[0]), str(item[1])))
        emp_id = (self.current_employee or {}).get('id')
        if emp_id and not any(k == 'pk' for k, _ in out):
            out.insert(0, ('pk', str(emp_id)))
        # ZKAccess accepts plain PIN in Password; it encodes internally after saving.
        return out

    def save_employee_password_direct(self, new_pin: str) -> tuple[bool, str]:
        emp_id = (self.current_employee or {}).get('id')
        if not emp_id:
            return False, 'EmployeeID atual desconhecido'
        pairs = self._collect_employee_form_pairs(new_pin)
        if not pairs:
            return False, 'não consegui recolher campos do formulário Employee'
        # Replace any Password entries definitively, preserving duplicate unrelated fields.
        pairs = [(k, v) for k, v in pairs if k != 'Password'] + [('Password', str(new_pin))]
        if not any(k == 'pk' for k, _ in pairs):
            pairs.insert(0, ('pk', str(emp_id)))
        body = urlencode(pairs, doseq=True)
        base = self.cfg['zkaccess_url'].rstrip('/')
        url = f'{base}/data/personnel/Employee/{emp_id}/?virtual_app=personnel'
        self.log(f'ZKAccess: guardar EmployeeID={emp_id} por POST direto')
        try:
            resp = self.page.request.post(
                url,
                data=body,
                headers={
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Referer': f'{base}/data/personnel/Employee/',
                },
                timeout=30000,
            )
            text = resp.text()
            self.log(f'ZKAccess: resposta save EmployeeID={emp_id}: HTTP {resp.status} {text[:120]}')
            if resp.status == 200 and 'OK' in text.upper():
                return True, 'Password atualizada por POST direto'
            return False, f'HTTP {resp.status}: {text[:200]}'
        except Exception as exc:
            return False, f'erro no POST direto: {exc}'

    def update_password_if_needed(self, first_name: str, new_pin: str, dry_run: bool = True) -> dict:
        result = {'room': first_name, 'pin_zkaccess': '', 'pin_zkaccess_raw': '', 'status': '', 'message': ''}
        if not new_pin:
            result.update(status='sem_pin_cloudbeds', message='sem PIN no Cloudbeds')
            return result
        self.find_person_by_first_name(first_name)
        password_info = self.read_password()
        current_raw = password_info.get('raw', '')
        current = password_info.get('decoded', '')
        result['pin_zkaccess'] = current
        result['pin_zkaccess_raw'] = current_raw
        if current == new_pin:
            result.update(status='igual', message='PIN já está igual')
            self.log(f'ZKAccess: {first_name} já tem PIN {current}; sem alteração.')
            return result
        self.log(f'ZKAccess: {first_name} PIN atual(raw={current_raw}, decoded={current}) novo={new_pin}')
        if dry_run:
            result.update(status='dry_run_diferente', message='Dry-run: seria atualizado')
            return result
        ok, msg = self.save_employee_password_direct(new_pin)
        if ok:
            result.update(status='alterado', message=msg)
            self.log(f'ZKAccess: {first_name} atualizado para {new_pin}')
            return result
        # Fallback visual se o POST direto falhar
        self.log(f'ZKAccess: POST direto falhou ({msg}); vou tentar Save visual.')
        field_sel = password_info.get('selector') or '#id_Password'
        try:
            self.page.locator(field_sel).first.fill(str(new_pin), timeout=4000)
        except Exception:
            self.page.locator('#id_Password, input[name="Password"]').first.fill(str(new_pin), timeout=4000)
        for sel in ['text=Save', 'button:has-text("Save")', 'input[value="Save"]', '#id_edit_form_save']:
            try:
                self.page.locator(sel).first.click(timeout=5000)
                self.page.wait_for_timeout(1800)
                result.update(status='alterado', message='Password atualizada via Save visual')
                self.log(f'ZKAccess: {first_name} atualizado para {new_pin} via Save visual')
                return result
            except Exception:
                pass
        result.update(status='erro_save', message=f'POST direto e Save visual falharam: {msg}')
        screenshot(self.page, self.root, f'zkaccess_save_error_{first_name}', self)
        return result

    def update_passwords_batch(self, rows: list[dict], dry_run: bool = True) -> list[dict]:
        rooms = [r.get('room') for r in rows if r.get('room')]
        self.login()
        self.build_employee_cache(rooms)
        updated = []
        for r in rows:
            z = self.update_password_if_needed(r.get('room'), r.get('pin_cloudbeds'), dry_run=dry_run)
            rr = dict(r)
            rr.update(z)
            updated.append(rr)
        return updated
