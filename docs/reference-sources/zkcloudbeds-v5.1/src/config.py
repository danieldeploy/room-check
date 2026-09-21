import json
from pathlib import Path

def root_dir() -> Path:
    return Path(__file__).resolve().parents[1]

def load_config():
    with (root_dir() / 'config.json').open('r', encoding='utf-8') as f:
        return json.load(f)
