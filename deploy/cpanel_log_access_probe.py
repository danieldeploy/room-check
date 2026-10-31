#!/usr/bin/env python3
"""Read-only, filename-free access diagnostic for one old error log.

No deletion, content reading, writes or credential output.
"""
import json
import stat
import time

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_historical_log_cleanup import snapshot, LOG_ROOT


def probe():
    _, matches = snapshot(int(time.time()))
    if len(matches) != 1:
        print(json.dumps({"ok": True, "read_only": True, "candidates": len(matches),
                          "result": "no_unique_candidate"}))
        return
    relative, size, mtime = matches[0]
    basename = relative[len("logs/"):]
    absolute = LOG_ROOT + "/" + basename
    result = {"ok": True, "read_only": True, "candidate_mb": round(size / (1024*1024), 2)}

    def call(label, module, func, params, legacy=False):
        try:
            data = request_api(module, func, params, legacy=legacy)
            result[label] = {"api_status": "success"}
            return data
        except Rejected as err:
            result[label] = {"api_status": "rejected", "reason_code": str(err)}
            return None

    for label, path in (("uapi_relative_stat", relative), ("uapi_absolute_stat", absolute)):
        data = call(label, "Fileman", "get_file_information", {"path": path})
        if isinstance(data, list) and len(data) == 1:
            data = data[0]
        if isinstance(data, dict):
            try:
                result[label]["type_is_file"] = data.get("type") == "file"
                result[label]["size_matches"] = int(data.get("size", -1)) == size
                result[label]["mtime_matches"] = int(data.get("mtime", -1)) == mtime
            except (ValueError, TypeError):
                result[label]["metadata_parseable"] = False
    stat_rows = call("api2_statfiles", "Fileman", "statfiles",
                     {"dir": "logs", "files": basename}, legacy=True)
    if isinstance(stat_rows, dict) and isinstance(stat_rows.get("data"), list):
        found = [item for item in stat_rows["data"] if isinstance(item, dict)
                 and item.get("file") == basename]
        result["api2_statfiles"]["entries"] = len(found)
        if len(found) == 1:
            item = found[0]
            result["api2_statfiles"]["type_is_file"] = item.get("type") == "file"
            result["api2_statfiles"]["account_is_owner"] = item.get("user") == "welcome"
            try:
                mode = int(item.get("mode", 0))
                result["api2_statfiles"]["owner_write_bit"] = bool(stat.S_IMODE(mode) & stat.S_IWUSR)
            except (ValueError, TypeError):
                result["api2_statfiles"]["mode_parseable"] = False
    data = call("uapi_list_logs", "Fileman", "list_files",
                {"dir": "logs", "show_hidden": 1})
    if isinstance(data, list):
        result["uapi_list_logs"]["entries"] = len(data)
        result["uapi_list_logs"]["candidate_visible"] = any(
            isinstance(row, dict) and (row.get("file") or row.get("name")) == basename
            for row in data)
    print(json.dumps(result, sort_keys=True), flush=True)


if __name__ == "__main__":
    try:
        probe()
    except Rejected as err:
        print(json.dumps({"ok": False, "read_only": True, "error_code": str(err)}),
              flush=True)
        raise SystemExit(1)
