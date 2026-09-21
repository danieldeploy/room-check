import sys
from .config import load_config, root_dir
from .logger import Logger
from . import workflow


def main():
    root = root_dir()
    cfg = load_config()
    logger = Logger(root)
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'auto'
    if cmd == 'login_setup':
        workflow.login_setup(cfg, root, logger)
    elif cmd == 'cloudbeds':
        workflow.run_cloudbeds(cfg, root, logger)
    elif cmd == 'zkaccess_test':
        workflow.run_zkaccess_test(cfg, root, logger)
    elif cmd == 'auto':
        workflow.run_auto(cfg, root, logger)
    else:
        raise SystemExit(f'Comando desconhecido: {cmd}')

if __name__ == '__main__':
    main()
