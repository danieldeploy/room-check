from pathlib import Path


def launch_context(playwright, root: Path, profile_name: str, cfg: dict):
    profile = root / 'browser_profiles' / profile_name
    profile.mkdir(parents=True, exist_ok=True)
    return playwright.chromium.launch_persistent_context(
        user_data_dir=str(profile),
        headless=bool(cfg.get('headless', False)),
        viewport={'width': 1400, 'height': 950},
        accept_downloads=True,
    )
