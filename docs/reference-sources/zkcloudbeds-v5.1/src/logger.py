from datetime import datetime
from pathlib import Path

class Logger:
    def __init__(self, root: Path):
        self.root = Path(root)
        self.logs = self.root / 'logs'
        self.logs.mkdir(exist_ok=True)
        self.file = self.logs / f'run_{datetime.now():%Y%m%d}.log'

    def log(self, msg: str):
        line = f'[{datetime.now():%Y-%m-%d %H:%M:%S}] {msg}'
        print(line, flush=True)
        with self.file.open('a', encoding='utf-8') as f:
            f.write(line + '\n')
