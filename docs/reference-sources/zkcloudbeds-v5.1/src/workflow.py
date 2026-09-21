from playwright.sync_api import sync_playwright
from .browser import launch_context
from .cloudbeds import CloudbedsClient
from .zkaccess import ZKAccessClient
from .utils import save_csv, save_html_report


def login_setup(cfg, root, logger):
    with sync_playwright() as p:
        cb_ctx = launch_context(p, root, 'cloudbeds', cfg)
        cb_page = cb_ctx.new_page()
        cb_page.goto(cfg['cloudbeds_dashboard_url'], wait_until='domcontentloaded')
        logger.log('Cloudbeds aberto. Faz login/MFA se necessário; aguarda 20 segundos.')
        cb_page.wait_for_timeout(20000)
        cb_ctx.close()

        zk_ctx = launch_context(p, root, 'zkaccess', cfg)
        zk_page = zk_ctx.new_page()
        zk = ZKAccessClient(zk_page, cfg, root, logger)
        zk.login()
        zk_ctx.close()


def run_cloudbeds(cfg, root, logger):
    with sync_playwright() as p:
        ctx = launch_context(p, root, 'cloudbeds', cfg)
        page = ctx.new_page()
        cb = CloudbedsClient(page, cfg, root, logger)
        results = cb.collect_pins_from_arrivals_today()
        ctx.close()
        return results


def run_zkaccess_test(cfg, root, logger):
    with sync_playwright() as p:
        ctx = launch_context(p, root, 'zkaccess', cfg)
        page = ctx.new_page()
        zk = ZKAccessClient(page, cfg, root, logger)
        zk.login()
        zk.build_employee_cache(['Room 1', 'Room 2', 'Room 3', 'Room 4', 'Room 5', 'Room 6'])
        logger.log('ZKAccess teste OK: login confirmado e cache batch criado.')
        ctx.close()


def run_auto(cfg, root, logger):
    rows = run_cloudbeds(cfg, root, logger)
    logger.log('CLOUDBEDS concluído. A passar ao ZKAccess...')
    dry_run = bool(cfg.get('dry_run', True))
    with sync_playwright() as p:
        ctx = launch_context(p, root, 'zkaccess', cfg)
        page = ctx.new_page()
        zk = ZKAccessClient(page, cfg, root, logger)
        updated = zk.update_passwords_batch(rows, dry_run=dry_run)
        ctx.close()

    csv_path = save_csv(root, updated, 'final_report')
    html_path = save_html_report(root, updated, 'final_report')
    logger.log(f'RELATÓRIO FINAL CSV guardado em {csv_path}')
    logger.log(f'RELATÓRIO FINAL HTML guardado em {html_path}')
    for r in updated:
        logger.log(f"FINAL: {r.get('room')} | {r.get('guest')} | CB={r.get('pin_cloudbeds')} | ZK={r.get('pin_zkaccess')} | {r.get('status')}")
    return updated
