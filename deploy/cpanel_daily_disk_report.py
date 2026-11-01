#!/usr/bin/env python3
"""Write a bounded, private daily WHM TXT audit after the scheduled maintenance.

Only metadata/counts. No mail contents, private file contents (except older
reports in the same exact namespace), secrets, user names or arbitrary paths.
Runs with the existing protected WHM token; server-side PHP can consume alerts.
"""
import datetime as dt
import hashlib
import json
import os
import re
import sys
from zoneinfo import ZoneInfo

from cpanel_deep_audit import backups, browsers, disk_usage, logs, search, BACKUP, BROWSER
from cpanel_disk_cleanup import Rejected, request_api

HOME = "/home/welcome"
PRIVATE = HOME + "/room-check-private"
TZ = ZoneInfo("Europe/Lisbon")
FILE_RE = re.compile(r"whm-disk-(20[0-9]{2}-[0-9]{2}-[0-9]{2})\.txt\Z")
REPORT_KEYS = ("usage_mb", "quota_percent", "logs_mb", "backup_mb", "cache_mb", "npm_mb",
               "mail_mb", "private_mb", "root_hashes")
MAX_TEXT_BYTES = 8000
MAX_ROOT_NAMES = 350
MB = 1024 * 1024
KNOWN = {".cache", ".npm", ".trash", "logs", "mail", "public_html",
         "room-check-backups", "room-check-private", "tmp", "ssl", "etc",
         "public_ftp", ".cpanel", ".softaculous", ".trash", "repositories"}
SAFE_OUTCOMES = {"success", "failure", "skipped", "cancelled", "unknown"}


def require(flag, code):
    if not flag:
        raise Rejected(code)


def directory(path):
    require(path in (PRIVATE, HOME), "invalid_directory")
    rows = request_api("Fileman", "list_files", {"dir": path, "show_hidden": 1})
    require(isinstance(rows, list) and len(rows) <= 1200, "invalid_directory_listing")
    items = {}
    for row in rows:
        require(isinstance(row, dict), "invalid_directory_listing")
        name = row.get("file") or row.get("name")
        require(isinstance(name, str) and name not in ("", ".", "..")
                and "/" not in name and "\\" not in name and name not in items,
                "invalid_directory_entry")
        items[name] = row
    return items


def root_hashes():
    entries = directory(HOME)
    # No actual directory names or content are exported. Comparison only.
    names = sorted(name for name, row in entries.items()
                   if row.get("type") == "dir" and name not in KNOWN)
    require(len(names) <= MAX_ROOT_NAMES, "unexpected_directory_count")
    return ",".join(hashlib.sha256(name.encode("utf-8")).hexdigest()[:16] for name in names)


def previous_report(entries, date_str):
    names = sorted((name for name, info in entries.items()
                    if info.get("type") == "file" and FILE_RE.fullmatch(name)
                    and FILE_RE.fullmatch(name).group(1) < date_str), reverse=True)
    if not names:
        return {}
    name = names[0]
    response = request_api("Fileman", "get_file_content",
                           {"dir": PRIVATE, "file": name, "to_charset": "utf-8"})
    raw = response.get("content") if isinstance(response, dict) else None
    require(isinstance(raw, str) and len(raw.encode("utf-8")) <= MAX_TEXT_BYTES,
            "previous_report_unreadable")
    values = {}
    for line in raw.splitlines():
        key, sep, value = line.partition("=")
        if sep and key in REPORT_KEYS:
            values[key] = value
    try:
        return {
            "usage_mb": float(values["usage_mb"]),
            "logs_mb": float(values["logs_mb"]),
            "root_hashes": set(filter(None, values.get("root_hashes", "").split(","))),
        }
    except (KeyError, ValueError):
        raise Rejected("previous_report_invalid") from None


def audit():
    summary = disk_usage()
    used = float(summary["quota_used_mb"])
    limit = float(summary["quota_limit_mb"])
    require(0 <= used and 0 < limit <= 1e9 and used <= limit * 1.05, "invalid_quota")
    dirs = summary["directories_mb"]
    audit_errors = []
    backup_data = {}
    browser_data = {}
    log_data = {}
    try:
        backup_data = backups(list(search(BACKUP.rstrip("/"))))
    except Rejected:
        audit_errors.append("backup_scan_failed")
    try:
        browser_data = browsers(list(search(BROWSER.rstrip("/"))))
    except Rejected:
        audit_errors.append("browser_scan_failed")
    try:
        log_data = logs()
    except Rejected:
        audit_errors.append("log_scan_failed")
    return summary, backup_data, browser_data, log_data, audit_errors


def outcome(name):
    value = os.environ.get(name, "unknown").strip()
    return value if value in SAFE_OUTCOMES else "unknown"


def compute_alert(pct, growth, log_growth, new_roots, browser_versions, audit_errors, outcomes):
    reasons = []
    if pct >= 90:
        reasons.append("quota90")
    elif pct >= 80:
        reasons.append("quota80")
    if growth is not None and growth >= 250:
        reasons.append("growth")
    if log_growth is not None and log_growth >= 70:
        reasons.append("logs_growth")
    if browser_versions:
        reasons.append("linux_browser_returned")
    if audit_errors or any(x != "success" for x in outcomes.values()):
        reasons.append("maintenance_issue")
    # A new folder is a review candidate, not an automatic WhatsApp alert;
    # unlike significant growth, directory creation alone is not an incident.
    priority = ("quota90", "maintenance_issue", "linux_browser_returned",
                "growth", "logs_growth", "quota80")
    selected = next((x for x in priority if x in reasons), "none")
    status = "critical" if selected in ("quota90", "maintenance_issue") else (
        "warning" if selected != "none" else "ok")
    details = {
        "quota90": "Ocupacao do disco >= 90 por cento",
        "quota80": "Ocupacao do disco >= 80 por cento",
        "growth": "Crescimento anormal do disco desde o ultimo relatorio",
        "logs_growth": "Crescimento anormal dos logs",
        "linux_browser_returned": "Browser Linux Puppeteer foi instalado novamente",
        "maintenance_issue": "Falha ou auditoria incompleta na manutencao diaria",
        "none": "Sem alerta",
    }
    actions = {
        "quota90": "Verificar quota e candidatos seguros antes de remover dados",
        "quota80": "Rever maiores categorias e plano de limpeza",
        "growth": "Comparar categorias do relatorio TXT privado",
        "logs_growth": "Investigar origem do log ativo, nao apagar sem verificacao",
        "linux_browser_returned": "Confirmar dependencia antes de eliminar o navegador",
        "maintenance_issue": "Consultar GitHub Actions e confirmar a causa da falha",
        "none": "Nenhuma acao necessaria",
    }
    return status, selected, details[selected], actions[selected], reasons


def report(now, previous, summary, backup_data, browser_data, log_data, errors, roots, outcomes):
    datestr = now.strftime("%Y-%m-%d")
    used = float(summary["quota_used_mb"])
    limit = float(summary["quota_limit_mb"])
    pct = round(100 * used / limit, 2)
    dirs = summary["directories_mb"]
    logs_mb = float(log_data.get("bytes_recursive_mb", dirs.get("logs", 0)))
    growth = round(used - previous["usage_mb"], 1) if previous else None
    log_growth = round(logs_mb - previous["logs_mb"], 1) if previous else None
    root_set = set(filter(None, roots.split(",")))
    new_roots = len(root_set - previous["root_hashes"]) if previous else 0
    browser_versions = browser_data.get("installed_version_sizes_mb", {})
    status, alert, detail, action, reasons = compute_alert(
        pct, growth, log_growth, new_roots, browser_versions, errors, outcomes)
    fields = [
        ("schema", "whm-disk-v1"),
        ("date_lisbon", datestr),
        ("generated_utc", dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")),
        ("status", status),
        ("alert_code", alert),
        ("alert_detail", detail),
        ("alert_action", action),
        ("usage_mb", f"{used:.1f}"),
        ("limit_mb", f"{limit:.1f}"),
        ("quota_percent", f"{pct:.2f}"),
        ("usage_change_mb", "unavailable" if growth is None else f"{growth:+.1f}"),
        ("logs_change_mb", "unavailable" if log_growth is None else f"{log_growth:+.1f}"),
        ("logs_mb", f"{logs_mb:.1f}"),
        ("backup_mb", f"{dirs.get('room-check-backups',0):.1f}"),
        ("backup_count", str(backup_data.get("directories_with_files", "unavailable"))),
        ("backups_redundant_candidates_not_approved", str(
            backup_data.get("same_day_redundant_candidates", {}).get("directories", "unavailable"))),
        ("cache_mb", f"{dirs.get('.cache',0):.1f}"),
        ("npm_mb", f"{dirs.get('.npm',0):.1f}"),
        ("mail_mb", f"{dirs.get('mail',0):.1f}"),
        ("private_mb", f"{dirs.get('room-check-private',0):.1f}"),
        ("public_mb", f"{dirs.get('public_html',0):.1f}"),
        ("browser_versions_detected", str(len(browser_versions))),
        ("root_other_dirs", str(len(root_set))),
        ("root_new_dirs", str(new_roots)),
        ("root_hashes", roots),
        ("backup_step", outcomes["backup"]),
        ("cache_step", outcomes["cache"]),
        ("inventory_step", outcomes["inventory"]),
        ("audit_errors", ",".join(errors) if errors else "none"),
        ("issue_codes", ",".join(reasons) if reasons else "none"),
        ("process_audit", "not_available_via_reseller_whm_api"),
        ("security", "metadata_only_private_whm_report"),
    ]
    payload = "".join(f"{key}={value}\n" for key, value in fields)
    require(len(payload.encode("utf-8")) < MAX_TEXT_BYTES, "report_too_large")
    return payload, status, alert, pct, new_roots


def write_once(name, payload, entries):
    require(FILE_RE.fullmatch(name) is not None, "invalid_report_name")
    if name in entries:
        require(entries[name].get("type") == "file", "unexpected_report_type")
    request_api("Fileman", "save_file_content",
                {"dir": PRIVATE, "file": name, "content": payload,
                 "from_charset": "utf-8", "to_charset": "utf-8"}, form=True)
    readback = request_api("Fileman", "get_file_content",
                           {"dir": PRIVATE, "file": name, "to_charset": "utf-8"})
    require(isinstance(readback, dict)
            and readback.get("content") == payload, "report_write_not_verified")


def main():
    now = dt.datetime.now(TZ)
    date_str = now.strftime("%Y-%m-%d")
    entries = directory(PRIVATE)
    previous = previous_report(entries, date_str)
    summary, backup_data, browser_data, log_data, errors = audit()
    roots = root_hashes()
    outcomes = {k: outcome(v) for k, v in (
        ("backup", "BACKUP_STEP"), ("cache", "CACHE_STEP"), ("inventory", "INVENTORY_STEP"))}
    text, status, alert, pct, new_dirs = report(
        now, previous, summary, backup_data, browser_data, log_data, errors, roots, outcomes)
    name = f"whm-disk-{date_str}.txt"
    write_once(name, text, entries)
    print(json.dumps({"ok": True, "report": "private_daily_txt",
                      "date": date_str, "status": status, "alert": alert,
                      "quota_percent": pct, "new_root_directories": new_dirs,
                      "readback_verified": True, "whatsapp_delivery": "separate_private_hub_cron"},
                     sort_keys=True), flush=True)


if __name__ == "__main__":
    try:
        main()
    except Rejected as error:
        print(json.dumps({"ok": False, "error": str(error)}), flush=True)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"error":"daily_report_unexpected_failure"}', flush=True)
        sys.exit(1)
