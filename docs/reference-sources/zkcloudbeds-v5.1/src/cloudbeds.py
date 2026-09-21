from pathlib import Path
from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from .utils import extract_pin_from_notes, save_csv, screenshot


class CloudbedsClient:
    def __init__(self, page, cfg: dict, root: Path, logger):
        self.page = page
        self.cfg = cfg
        self.root = root
        self.log = logger.log
        self.timeout = int(cfg.get('timeouts', {}).get('element', 30000))

    def open_dashboard(self):
        url = self.cfg['cloudbeds_dashboard_url']
        self.log(f'A abrir Cloudbeds: {url}')
        self.page.goto(url, wait_until='domcontentloaded', timeout=int(self.cfg.get('timeouts', {}).get('page', 60000)))
        self.page.wait_for_timeout(3000)

    def ensure_arrivals_today(self):
        for sel in ['text=Arrivals', "a:has-text('Arrivals')", "button:has-text('Arrivals')"]:
            try:
                self.page.locator(sel).first.click(timeout=8000)
                self.log(f'Cliquei em Arrivals com seletor: {sel}')
                break
            except Exception:
                pass
        self.page.wait_for_timeout(1000)
        for sel in ['text=Today', "a:has-text('Today')", "button:has-text('Today')"]:
            try:
                self.page.locator(sel).first.click(timeout=5000)
                self.log(f'Cliquei em Today com seletor: {sel}')
                break
            except Exception:
                pass
        self.page.wait_for_selector('#tab_arrivals-today', timeout=self.timeout)
        self.page.wait_for_selector('#tab_arrivals-today tr.dashboard-res-row', timeout=self.timeout)
        screenshot(self.page, self.root, 'cloudbeds_arrivals_today', self)

    def collect_arrivals_today(self) -> list[dict]:
        self.open_dashboard()
        self.ensure_arrivals_today()
        rows = self.page.evaluate('''
        () => {
          const root = document.querySelector('#tab_arrivals-today');
          if (!root) return [];
          const out = [];
          for (const tr of root.querySelectorAll('tbody tr.dashboard-res-row')) {
            const tds = Array.from(tr.querySelectorAll('td'));
            const guestLink = tr.querySelector('a.show-reservation[data-dd-action-name="Reservation Guest"]');
            const noteIcon = tr.querySelector('i.dashboard_notes');
            const status = (tr.querySelector('.status_color_arrival, .wrap-status')?.innerText || '').trim();
            out.push({
              reservation_id: tr.getAttribute('data-res-id') || guestLink?.getAttribute('data-id') || '',
              guest: (guestLink?.innerText || '').trim(),
              room: (tds[2]?.innerText || '').trim(),
              status: status,
              has_notes: !!(noteIcon && noteIcon.classList.contains('has-notes')),
              identifier: noteIcon?.getAttribute('data-identifier') || '',
              booking_room_id: noteIcon?.getAttribute('data-booking-room-id') || ''
            });
          }
          return out.filter(x => x.reservation_id && x.guest && x.room);
        }
        ''')
        self.log(f'CLOUDBEDS: reservas em #tab_arrivals-today: {len(rows)}')
        for r in rows:
            self.log(f"  Guest='{r['guest']}' Room='{r['room']}' ReservationID={r['reservation_id']} Status='{r.get('status','')}' HasNotes={r.get('has_notes')}")
        return rows

    def _extract_notes_from_json(self, data) -> str:
        parts = []

        def walk(obj):
            if isinstance(obj, dict):
                if isinstance(obj.get('notes'), str):
                    parts.append(obj.get('notes'))
                for key in ('note', 'body', 'text', 'description', 'message'):
                    if isinstance(obj.get(key), str) and 'pin' in obj.get(key, '').lower():
                        parts.append(obj.get(key))
                for v in obj.values():
                    walk(v)
            elif isinstance(obj, list):
                for v in obj:
                    walk(v)
        walk(data)
        return '\n'.join(dict.fromkeys([p for p in parts if p]))

    def read_pin_from_reservation_api(self, reservation: dict) -> dict:
        rid = reservation['reservation_id']
        url = self.cfg["cloudbeds_reservation_url_template"].format(reservation_id=rid)
        self.log(f'A abrir reserva por data-res-id: {rid}')
        api_payloads = []

        def on_response(response):
            try:
                if '/connect/reservations/get_reservation' in response.url and response.request.method.upper() == 'POST':
                    try:
                        api_payloads.append(response.json())
                    except Exception:
                        try:
                            api_payloads.append(response.text())
                        except Exception:
                            pass
            except Exception:
                pass

        self.page.on('response', on_response)
        try:
            # Playwright Python sync API usa expect_response(), não wait_for_response().
            # Armamos o listener antes de abrir a reserva para capturar o POST get_reservation.
            try:
                with self.page.expect_response(
                    lambda r: '/connect/reservations/get_reservation' in r.url and r.request.method.upper() == 'POST',
                    timeout=25000,
                ) as response_info:
                    self.page.goto(url, wait_until='domcontentloaded', timeout=int(self.cfg.get('timeouts', {}).get('page', 60000)))
                resp = response_info.value
                try:
                    api_payloads.append(resp.json())
                except Exception:
                    try:
                        api_payloads.append(resp.text())
                    except Exception:
                        pass
            except PlaywrightTimeoutError:
                self.log('Aviso: não capturei get_reservation; vou tentar ler DOM como fallback.')
                # Garante que a reserva abriu mesmo que o POST não tenha sido capturado.
                if f'/reservations/{rid}' not in self.page.url:
                    self.page.goto(url, wait_until='domcontentloaded', timeout=int(self.cfg.get('timeouts', {}).get('page', 60000)))

            self.page.wait_for_timeout(2500)
            notes_text = ''
            for payload in api_payloads:
                if isinstance(payload, (dict, list)):
                    notes_text += '\n' + self._extract_notes_from_json(payload)
                elif isinstance(payload, str):
                    notes_text += '\n' + payload

            pin = extract_pin_from_notes(notes_text, self.cfg.get('pin_regex'))
            if not pin:
                dom_text = self.page.locator('body').inner_text(timeout=10000)
                pin = extract_pin_from_notes(dom_text, self.cfg.get('pin_regex'))
                if pin:
                    notes_text = dom_text

            result = dict(reservation)
            result['pin_cloudbeds'] = pin
            result['pin_zkaccess'] = ''
            result['status'] = 'ok' if pin else 'sem_pin'
            result['message'] = 'pin obtido via get_reservation' if pin and notes_text else 'pin não encontrado'
            self.log(f"CLOUDBEDS: Guest={result['guest']} Room={result['room']} PIN={pin} status={result['status']}")
            if not pin:
                screenshot(self.page, self.root, f'cloudbeds_no_pin_{rid}', self)
            return result
        finally:
            try:
                self.page.remove_listener('response', on_response)
            except Exception:
                pass

    def collect_pins_from_arrivals_today(self) -> list[dict]:
        arrivals = self.collect_arrivals_today()
        results = []
        for idx, res in enumerate(arrivals, 1):
            self.log(f'--- Cloudbeds reserva {idx}/{len(arrivals)} ---')
            results.append(self.read_pin_from_reservation_api(res))
        path = save_csv(self.root, results, 'cloudbeds_pins')
        self.log(f'CLOUDBEDS: tabela de PINs guardada em {path}')
        self.log('RESULTADOS CLOUDBEDS:')
        for r in results:
            self.log(f"  {r.get('room')} | {r.get('guest')} | PIN={r.get('pin_cloudbeds')} | status={r.get('status')}")
        return results
