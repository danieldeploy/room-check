#!/usr/bin/env python3
"""One-off, read-only WHM welcome-account log *content* review.

No raw log line, domain, path, IP, URL, user, secret or filename is emitted.
Never deletes, truncates, rotates or uploads any server file. Samples PHP
error-log tails through cPanel's documented LogManager UAPI, and reads only
small regular plaintext files directly. Large logs and compressed archives
are explicitly marked as not fully inspected.
"""
import collections
import datetime as dt
import json
import re
import sys
import time

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_npm_finisher import LOG_ROOT, log_category, scoped_search

MB = 1024 * 1024
MAX_LOGS = 150
MAX_DOMAINS = 12
MAX_SMALL_FILE = 65536
MAX_ROWS = 500
MAX_SAMPLE_CHARS = 500000
MAX_CALLS = 36
SAFE_NAME = re.compile(r"[A-Za-z0-9_.-]{1,160}\Z")
DOMAIN = re.compile(r"[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+\Z")

PATTERNS = [
    ("fatal", re.compile(r"\b(?:PHP Fatal error|uncaught (?:exception|error)|stack trace)\b", re.I)),
    ("parse", re.compile(r"\b(?:PHP Parse error|syntax error|parse error)\b", re.I)),
    ("deprecated", re.compile(r"\b(?:PHP Deprecated|deprecated:)\b", re.I)),
    ("warning", re.compile(r"\b(?:PHP Warning|warning:)\b", re.I)),
    ("notice", re.compile(r"\b(?:PHP Notice|notice:)\b", re.I)),
    ("database", re.compile(r"\b(?:SQLSTATE|mysqli_|PDOException|mysql.*error|database.*failed)\b", re.I)),
    ("permission", re.compile(r"\b(?:permission denied|access denied|forbidden|failed to open stream)\b", re.I)),
    ("disk_full", re.compile(r"\b(?:no space left on device|disk quota exceeded|ENOSPC)\b", re.I)),
    ("missing_file", re.compile(r"\b(?:no such file or directory|file not found|not found)\b", re.I)),
    ("timeout", re.compile(r"\b(?:timed out|timeout|maximum execution time)\b", re.I)),
    ("tls", re.compile(r"\b(?:SSL certificate|handshake failed|TLS alert)\b", re.I)),
    ("authentication", re.compile(r"\b(?:authentication failed|invalid credentials|unauthorized)\b", re.I)),
    ("http_5xx", re.compile(r'\s5\d\d\s+\d+(?:\s|$)')),
    ("http_4xx", re.compile(r'\s4\d\d\s+\d+(?:\s|$)')),
]

def classify(line):
    for label, pattern in PATTERNS:
        if pattern.search(line):
            return label
    return "other"

def summarize(lines):
    counts = collections.Counter()
    nonempty = 0
    for item in lines:
        if not isinstance(item, str):
            raise Rejected("invalid_log_line_type")
        if len(item) > 50000:
            item = item[:50000]
        if item.strip():
            nonempty += 1
            counts[classify(item)] += 1
    return {"lines_sampled": nonempty, "category_counts": dict(sorted(counts.items()))}

def text_lines(data):
    """Accept known UAPI response shapes; never stringify arbitrary dicts."""
    candidate = data
    for _ in range(4):
        if isinstance(candidate, str):
            if len(candidate) > MAX_SAMPLE_CHARS:
                raise Rejected("log_content_exceeds_sample_limit")
            return candidate.splitlines()[:MAX_ROWS]
        if isinstance(candidate, list):
            if len(candidate) > MAX_ROWS:
                candidate = candidate[-MAX_ROWS:]
            out = []
            for obj in candidate:
                if isinstance(obj, str):
                    out.append(obj)
                elif isinstance(obj, dict):
                    for field in ("line", "message", "text", "content"):
                        if isinstance(obj.get(field), str):
                            out.append(obj[field])
                            break
            return out
        if isinstance(candidate, dict):
            match = next((candidate[k] for k in ("lines", "entries", "content", "log", "data")
                          if k in candidate and isinstance(candidate[k], (str, dict, list))), None)
            if match is None:
                return []
            candidate = match
        else:
            return []
    return []

def inventory(rows, now):
    entries = []
    for row in rows:
        if row.get("type") != "file":
            continue
        path = row.get("file")
        if not isinstance(path, str) or not path.startswith(LOG_ROOT + "/"):
            raise Rejected("log_path_outside_scope")
        try:
            size, modified = int(row["size"]), int(row["mtime"])
        except (TypeError, ValueError, KeyError):
            raise Rejected("invalid_log_metadata") from None
        if not (0 <= size <= 512 * MB and 0 < modified < now + 3600):
            raise Rejected("invalid_log_metadata")
        entries.append((path, size, modified))
    if len(entries) > MAX_LOGS:
        raise Rejected("too_many_log_files")
    entries.sort(key=lambda x: (-x[1], x[0]))
    return entries

def domain_list():
    result = request_api("DomainInfo", "list_domains", {})
    if not isinstance(result, dict):
        raise Rejected("domain_listing_unavailable")
    names = []
    for k in ("main_domain",):
        if isinstance(result.get(k), str):
            names.append(result[k])
    for k in ("addon_domains", "sub_domains", "parked_domains"):
        val = result.get(k, [])
        if isinstance(val, list):
            names.extend(x for x in val if isinstance(x, str))
    unique = sorted(set(x.lower() for x in names if DOMAIN.fullmatch(x)))
    if len(unique) > MAX_DOMAINS:
        raise Rejected("too_many_domains")
    return unique

def read_small_file(path):
    basename = path[len(LOG_ROOT) + 1:]
    if ("/" in basename or not SAFE_NAME.fullmatch(basename)
            or basename.lower().endswith((".gz", ".zip", ".bz2", ".xz", ".zst"))):
        return {"state": "not_a_small_plain_log"}
    try:
        contents = request_api("Fileman", "get_file_content", {
            "dir": LOG_ROOT, "file": basename, "to_charset": "utf-8"})
        if not isinstance(contents, dict) or not isinstance(contents.get("content"), str):
            return {"state": "content_format_unavailable"}
        out = summarize(text_lines(contents["content"]))
        return {"state": "complete_plaintext", **out}
    except Rejected:
        return {"state": "content_api_unavailable"}

def analyze():
    now = time.time()
    rows = list(scoped_search(LOG_ROOT))
    entries = inventory(rows, now)
    report = {
        "ok": True, "read_only": True, "account": "welcome",
        "files_total": len(entries),
        "total_mb": round(sum(x[1] for x in entries) / MB, 2),
        "content_privacy": "aggregates_only_no_log_lines_or_names",
        "retention_changed": False, "files_deleted": 0,
        "files": [],
        "php_tail": [],
        "archive_settings": "unavailable",
        "sampling_limit": "large_logs_not_fully_read",
    }
    for index, (path, size, modified) in enumerate(entries, 1):
        relative = path[len(LOG_ROOT) + 1:]
        kind = log_category(path)
        entry = {
            "id": index, "type": kind,
            "size_mb": round(size / MB, 2),
            "age_days_since_modification": max(0, int((now - modified) // 86400)),
            "content": "not_accessed",
        }
        # The 110+ MiB file cannot be safely requested wholesale through the
        # WHM API, which caps each response to 1 MiB.
        if 0 < size <= MAX_SMALL_FILE and kind != "compressed_archive":
            entry["content_detail"] = read_small_file(path)
            entry["content"] = entry["content_detail"]["state"]
        elif kind == "compressed_archive":
            entry["content"] = "compressed_archive_not_opened"
        elif size > MAX_SMALL_FILE:
            entry["content"] = "large_log_no_whole_file_access"
        report["files"].append(entry)
    try:
        settings = request_api("LogManager", "get_settings", {})
        if isinstance(settings, dict):
            report["archive_settings"] = {
                "archive_enabled": settings.get("archive_logs") in (1, "1", True),
                "prune_enabled": settings.get("prune_archive") in (1, "1", True),
                "retention_days": settings.get("retention_days")
                if type(settings.get("retention_days")) is int
                   and 0 <= settings["retention_days"] <= 3650 else "unavailable",
            }
    except Rejected:
        pass
    try:
        domains = domain_list()
    except Rejected:
        domains = []
        report["domain_api_status"] = "unavailable"
    report["domains_checked"] = len(domains)
    calls = 0
    for domain_index, domain in enumerate(domains, 1):
        for file_id in ("php_fpm_error_log", "homedir_error_log", "docroot_error_log"):
            if calls >= MAX_CALLS:
                break
            calls += 1
            try:
                data = request_api("LogManager", "view_error_log", {
                    "domain": domain, "file_id": file_id, "limit": 350})
                lines = text_lines(data)
                item = {"domain_ordinal": domain_index, "source": file_id,
                        "access": "read_only", **summarize(lines)}
            except Rejected as error:
                item = {"domain_ordinal": domain_index, "source": file_id,
                        "access": "unavailable", "reason_code": str(error)}
            report["php_tail"].append(item)
    report["php_tail_calls"] = calls
    report["php_tail_lines_sampled"] = sum(x.get("lines_sampled", 0) for x in report["php_tail"])
    report["php_tail_error_counts"] = dict(sorted(sum(
        (collections.Counter(x.get("category_counts", {})) for x in report["php_tail"]),
        collections.Counter()).items()))
    report["coverage_note"] = (
        "This is content analysis of bounded PHP log tails and small plaintext logs; "
        "no complete scan of the 110MB file was possible via documented cPanel APIs."
    )
    print(json.dumps(report, ensure_ascii=True, sort_keys=True), flush=True)

if __name__ == "__main__":
    try:
        analyze()
    except Rejected as error:
        print(json.dumps({"ok": False, "read_only": True,
                          "error_code": str(error)}), flush=True)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"read_only":true,"error_code":"unexpected_log_audit_error"}', flush=True)
        sys.exit(1)
