#!/usr/bin/env python3
"""Read Cloudbeds mail in memory; emit only a fixed, content-free shape report.

Uses the existing protected hosting credential. Never signs in to a mailbox,
changes flags, downloads links, saves mail, or outputs addresses/subjects/URLs.
"""
import email.policy
import email.utils
from email.parser import BytesParser
from html import unescape
import json
import os
import re
import sys
import subprocess
import pathlib
import time
import urllib.parse
import urllib.request

from cpanel_api import Config, CpanelAPI, DeploymentError, WHM_ORIGIN, require

ROOTS = {
    "welcome": "/home/welcome/mail/welcomehostel.pt/info",
    "city": "/home/city/mail/citycenterhostel.pt/info",
}
MAX_MAIL = 4 * 1024 * 1024
MAX_RESPONSE = 28 * 1024 * 1024
WORDS = ("invoice", "receipt", "payment", "billing", "portal", "download", "login")
ERRORS = {"message_size", "mime_parts", "part_size", "link_limit", "invalid_listing",
          "invalid_name", "response_size", "whm_read_failed", "mail_read_failed",
          "directory_required", "listing_size", "ambiguous_folder", "invalid_content"}


def markers(text):
    return [word for word in WORDS if re.search(r"\b" + word + r"s?\b", text, re.I)]


def family(host):
    host = host.lower().rstrip(".")
    for base in ("cloudbeds.com", "zuora.com", "stripe.com", "chargebee.com"):
        if host == base or host.endswith("." + base):
            return base
    return "other"


def outside_window(value):
    try:
        return email.utils.parsedate_to_datetime(str(value)).timestamp() < time.time()-730*86400
    except (TypeError, ValueError, OverflowError):
        return False


def pdf_text_shape(text):
    """Only fixed label/format facts, never extracted text or invoice values."""
    labels = {
        "invoice_date": r"\binvoice\s+date\b", "issue_date": r"\b(?:issue\s+date|date\s+of\s+issue)\b",
        "invoice_number": r"\binvoice\s*(?:number|no\.?|#)\b", "invoice": r"\binvoice\b",
        "due_date": r"\bdue\s+date\b", "bill_to": r"\bbill(?:ed)?\s+to\b",
        "vat_number": r"\b(?:vat|tax)\s*(?:number|id|no\.?)\b", "nif": r"\bnif\b",
        "receipt": r"\breceipt\b", "payment_confirmation": r"\bpayment\s+confirmation\b",
    }
    formats = {"iso": r"20\d{2}-\d{2}-\d{2}",
               "numeric_slash": r"\d{1,2}/\d{1,2}/20\d{2}",
               "numeric_dash": r"\d{1,2}-\d{1,2}-20\d{2}",
               "month_first": r"[A-Za-z]{3,9}\s+\d{1,2},?\s+20\d{2}",
               "day_first": r"\d{1,2}\s+[A-Za-z]{3,9}\s+20\d{2}"}
    dates = {}
    for label in ("invoice_date", "issue_date", "due_date"):
        matches = list(re.finditer(labels[label], text, re.I))
        values = [text[m.end():m.end()+60].lstrip(" \t\r\n:") for m in matches]
        dates[label] = {"count": len(matches), "following_formats": sorted({
            name for value in values for name, pattern in formats.items() if re.match(pattern, value)})}
    return {"text_extracted": bool(text.strip()),
            "labels": [name for name, pattern in labels.items() if re.search(pattern, text, re.I)],
            "dates": dates,
            "cloudbeds": bool(re.search(r"\bcloudbeds\b", text, re.I)),
            "active_lines": bool(re.search(r"\bactive\s+lines\b", text, re.I)),
            "welcome": bool(re.search(r"\bwelcome\s+(?:hostel|guest)", text, re.I)),
            "city": bool(re.search(r"\bcity\s+(?:center|centre)\s+hostel", text, re.I)),
            "portuguese_tax_format": bool(re.search(r"\bPT\s*\d{9}\b", text, re.I)),
            "invoice_a_number": bool(re.search(r"\bA\d{6,12}\b", text)),
            "invoice_number_after_label": bool(re.search(r"\binvoice\s*(?:number|no\.?|#)?\s*:?\s*A\d{6,12}\b", text, re.I))}


def pdf_shape(content):
    if not content.startswith(b"%PDF-") or len(content) > 2*1024*1024:
        return {"error": "invalid_pdf"}
    try:
        result = subprocess.run(["pdftotext", "-f", "1", "-l", "10", "-layout", "-enc", "UTF-8", "-", "-"],
                                input=content, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=10, check=True)
        if len(result.stdout) > 2*1024*1024:
            return {"error": "text_limit"}
        return pdf_text_shape(result.stdout.decode("utf-8", errors="replace"))
    except (OSError, subprocess.SubprocessError):
        return {"error": "text_unavailable"}


def shape(raw):
    require(0 < len(raw) <= MAX_MAIL, "message_size")
    message = BytesParser(policy=email.policy.default).parsebytes(raw)
    senders = email.utils.getaddresses(message.get_all("From", []))
    sender_family = family(senders[0][1].rsplit("@", 1)[-1]) if len(senders) == 1 else "other"
    texts = []
    types = {"plain": 0, "html": 0, "pdf": 0, "other_attachment": 0}
    pdf_headers = 0
    pdf_shapes = []
    parts = list(message.walk())
    require(len(parts) <= 64, "mime_parts")
    for part in parts:
        if part.is_multipart():
            continue
        mime = part.get_content_type()
        content = part.get_payload(decode=True) or b""
        require(len(content) <= MAX_MAIL, "part_size")
        if mime == "application/pdf" or (part.get_filename() or "").lower().endswith(".pdf"):
            types["pdf"] += 1
            pdf_headers += int(content.startswith(b"%PDF-"))
            pdf_shapes.append(pdf_shape(content))
        elif part.get_content_disposition() == "attachment":
            types["other_attachment"] += 1
        elif mime in ("text/plain", "text/html"):
            types[mime.split("/")[1]] += 1
            try:
                texts.append(content.decode(part.get_content_charset() or "utf-8", errors="replace"))
            except LookupError:
                texts.append(content.decode("utf-8", errors="replace"))
    body = unescape("\n".join(texts))
    links = set(re.findall(r'https://[^\s<>"\x27]+', body, re.I))
    require(len(links) <= 200, "link_limit")
    link_shapes = []
    for link in links:
        try:
            url = urllib.parse.urlsplit(link.rstrip(".,)"))
            # Never retain a host, path segment, parameter name/value or fragment.
            item = {"provider": family(url.hostname or ""),
                    "path_markers": markers(urllib.parse.unquote(url.path)),
                    "pdf_path": url.path.lower().endswith(".pdf"),
                    "has_query": bool(url.query), "has_fragment": bool(url.fragment)}
            if item not in link_shapes:
                link_shapes.append(item)
        except ValueError:
            continue
    plain = re.sub(r"<[^>]*>", " ", body)
    authentication = {}
    checker = pathlib.Path(__file__).with_name("cloudbeds_mail_check.php")
    if types["pdf"] and checker.is_file():
        try:
            result = subprocess.run(["php", str(checker)], input=raw, stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL, timeout=30, check=True)
            facts = json.loads(result.stdout)
            for key in ("welcome_recipient", "city_recipient"):
                if type(facts.get(key)) is bool: authentication[key] = facts[key]
            if facts.get("auth") in ("verified", "unsigned", "unsupported", "unaligned", "expired", "unsigned_fields",
                    "dns_unavailable", "body_mismatch", "signature_mismatch", "unverified", "parse_failed"):
                authentication["auth"] = facts["auth"]
        except (OSError, ValueError, subprocess.SubprocessError):
            authentication = {"auth": "unavailable"}
    return {"sender_provider": sender_family, "mime": types,
            "mail_older_than_verification_window": outside_window(message.get("Date")),
            "authentication": authentication,
            "pdf_shapes": pdf_shapes,
            "dkim_providers": sorted(set(family(m.group(1)) for value in message.get_all("DKIM-Signature", [])
                for m in re.finditer(r"(?:^|;)\s*d=([^;\s]+)", str(value), re.I))),
            "pdf_signature_count": pdf_headers,
            "subject_markers": markers(str(message.get("Subject", ""))),
            "body_markers": markers(plain),
            "company_mentioned": bool(re.search(r"\bActive\s+Lines\b", plain, re.I)),
            "payment_confirmation": bool(re.search(r"payment\s+(?:was\s+)?(?:received|processed|successful)|thank\s+you\s+for\s+your\s+payment", plain, re.I)),
            "invoice_attached_wording": bool(re.search(r"(?:attached.{0,60}invoice|invoice.{0,60}attached)", plain, re.I | re.S)),
            "links": sorted(link_shapes, key=lambda x: json.dumps(x, sort_keys=True))}


def rows(value):
    if isinstance(value, list):
        return value
    if isinstance(value, dict) and isinstance(value.get("files"), list):
        return value["files"]
    raise DeploymentError("invalid_listing")


def child_name(row):
    name = row.get("file", "")
    require(isinstance(name, str) and 0 < len(name) <= 512
            and name not in (".", "..") and not re.search(r"[/\\\x00-\x1f]", name), "invalid_name")
    return name


def error_facts(errors):
    """Classify failures without printing even a fragment of the server response."""
    text = json.dumps(errors).lower()
    return {key: any(word in text for word in words) for key, words in {
        "missing_path": ("does not exist", "no such file", "not found", "could not find"),
        "permission": ("permission", "denied", "not permitted"),
        "parameter": ("parameter", "argument", "must specify", "must provide"),
        "function": ("function", "module", "disabled", "not implemented"),
        "encoding": ("charset", "encoding", "utf-8"),
        "mentions_dir": ("dir",), "mentions_path": ("path",),
        "mentions_types": ("types",), "mentions_file": ("file",),
    }.items()}


class MailAPI:
    def __init__(self, account):
        require(account in ROOTS, "account_not_allowed")
        self.account = account
        self.root = ROOTS[account]
        self.allowed_dirs = {self.root}
        self.allowed_files = set()
        self.stage = "mailbox_metadata"
        self.last_operation = "none"
        self.failure_facts = {}
        self.config = Config(token=os.environ.get("WHM_API_TOKEN", ""), transport="whm",
                             origin=WHM_ORIGIN, whm_target_account=account)
        self.opener = CpanelAPI(self.config).opener

    def call(self, function, **params):
        require(function in ("list_files", "get_file_content", "get_file_information"), "read_not_allowed")
        if function == "list_files":
            require(set(params) == {"dir", "show_hidden", "types"}
                    and params["dir"] in self.allowed_dirs
                    and params["show_hidden"] == 1 and params["types"] == "file|dir", "listing_not_allowed")
        elif function == "get_file_information":
            require(set(params) == {"path", "show_hidden"} and params["path"] in self.allowed_dirs
                    and params["show_hidden"] == 1, "metadata_not_allowed")
        else:
            require(set(params) == {"dir", "file", "from_charset", "to_charset", "update_html_document_encoding"}
                    and (params["dir"], params["file"]) in self.allowed_files
                    and params["from_charset"] == "ISO-8859-1"
                    and params["to_charset"] == "UTF-8" and params["update_html_document_encoding"] == 0, "content_not_allowed")
        self.last_operation = function
        query = {"api.version": 1, "cpanel.user": self.account, "cpanel.module": "Fileman",
                 "cpanel.function": function, **params}
        request = urllib.request.Request(WHM_ORIGIN + "/json-api/uapi_cpanel",
            data=urllib.parse.urlencode(query).encode(),
            headers={"Authorization": "whm fazenda:" + self.config.token})
        with self.opener.open(request, timeout=20) as response:
            raw = response.read(MAX_RESPONSE + 1)
        require(len(raw) <= MAX_RESPONSE, "response_size")
        payload = json.loads(raw)
        require(payload.get("metadata", {}).get("result") == 1, "whm_read_failed")
        result = payload.get("data", {}).get("uapi", {})
        if result.get("status") != 1 or result.get("errors"):
            self.failure_facts = error_facts(result.get("errors"))
        require(result.get("status") == 1 and not result.get("errors"), "mail_read_failed")
        return result.get("data")

    def listing(self, directory):
        meta = self.call("get_file_information", path=directory, show_hidden=1)
        meta_rows = meta if isinstance(meta, list) else [meta]
        require(len(meta_rows) == 1 and meta_rows[0].get("type") == "dir", "directory_required")
        result = rows(self.call("list_files", dir=directory, show_hidden=1, types="file|dir"))
        require(len(result) <= 10000, "listing_size")
        return result

    def inspect(self):
        folders = []
        for row in self.listing(self.root):
            if row.get("file") in (".", ".."):
                continue
            name = child_name(row)
            if row.get("type") == "dir" and re.fullmatch(r"\.(?:INBOX\.)?CLOUDBEDS", name, re.I):
                folders.append(self.root + "/" + name)
        require(len(folders) <= 2, "ambiguous_folder")
        candidates = []
        for folder in folders:
            self.stage = "cloudbeds_folder"
            self.allowed_dirs.add(folder)
            for row in self.listing(folder):
                if row.get("file") in (".", ".."):
                    continue
                name = child_name(row)
                if row.get("type") != "dir" or name not in ("cur", "new"):
                    continue
                directory = folder + "/" + name
                self.allowed_dirs.add(directory)
                self.stage = "maildir_listing"
                for entry in self.listing(directory):
                    if entry.get("file") in (".", ".."):
                        continue
                    filename = child_name(entry)
                    if entry.get("type") != "file":
                        continue
                    size = int(entry.get("size", 0))
                    if 0 < size <= MAX_MAIL:
                        candidates.append((float(entry.get("mtime", 0)), directory, filename))
        reports = []
        ordered=sorted(candidates, reverse=True)
        sample=list(dict.fromkeys(ordered[:20]+ordered[-20:]))
        for _, directory, filename in sample:
            self.stage = "message_read"
            self.allowed_files.add((directory, filename))
            value = self.call("get_file_content", dir=directory, file=filename,
                              from_charset="ISO-8859-1", to_charset="UTF-8", update_html_document_encoding=0)
            if isinstance(value, list) and len(value) == 1:
                value = value[0]
            require(isinstance(value, dict) and isinstance(value.get("content"), str), "invalid_content")
            raw = value["content"].encode("latin-1")
            self.stage = "mime_shape"
            reports.append(shape(raw))
            del raw, value
        return {"account": self.account, "folder_found": bool(folders),
                "sample_count": len(reports), "samples": reports}


def main():
    failed = False
    for account in ROOTS:
        api = None
        try:
            api = MailAPI(account)
            print(json.dumps({"cloudbeds_mail_shape": api.inspect()}), flush=True)
        except Exception as failure:
            # No exception repr, server response, file name or original mail in CI logs.
            code = str(failure) if isinstance(failure, DeploymentError) and str(failure) in ERRORS else "inspection_unavailable"
            facts = {"account": account, "error": code}
            if api is not None:
                facts.update(stage=api.stage, operation=api.last_operation, error_facts=api.failure_facts)
            print(json.dumps({"cloudbeds_mail_shape": facts}), flush=True)
            failed = True
    return int(failed)


if __name__ == "__main__":
    sys.exit(main())
