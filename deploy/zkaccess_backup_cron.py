#!/usr/bin/env python3
"""Provision exactly one independent ZKAccess backup archive cron through protected WHM.

No credentials in source, command lines, URL parameters, or output. The existing
production deploy job supplies WHM_API_TOKEN via its protected environment.
"""
import argparse
import json
import sys
from cpanel_disk_cleanup import Rejected, request_api

COMMAND = (
    "cd /home/welcome && /opt/cpanel/ea-php81/root/usr/bin/php -q "
    "/home/welcome/room-check-private/cron/zkaccess-backups.php >/dev/null 2>&1"
)
SCHEDULE = {
    "command": COMMAND, "minute": "*/5", "hour": "*",
    "day": "*", "month": "*", "weekday": "*",
}
NAME = "zkaccess-backups.php"


def cron_call(action, parameters=None):
    parameters = parameters or {}
    if action == "listcron":
        if parameters:
            raise Rejected("cron_parameters_not_allowed")
    elif action == "add_line":
        if parameters != SCHEDULE:
            raise Rejected("cron_parameters_not_allowed")
    else:
        raise Rejected("cron_operation_not_allowed")
    result = request_api("Cron", action, parameters, legacy=True)
    if not isinstance(result, dict) or result.get("error"):
        raise Rejected("invalid_cron_response")
    data = result.get("data")
    if not isinstance(data, list):
        raise Rejected("invalid_cron_response")
    if action == "add_line":
        if len(data) != 1 or data[0].get("status") not in (1, "1", True):
            raise Rejected("cron_creation_not_verified")
    return data


def inspect():
    lines = cron_call("listcron")
    if not all(isinstance(item, dict) for item in lines):
        raise Rejected("invalid_cron_list")
    matches = [item for item in lines if NAME in str(item.get("command", ""))]
    if len(matches) > 1:
        raise Rejected("duplicate_zkaccess_cron")
    if matches:
        found = matches[0]
        if found.get("command") != COMMAND or any(
            str(found.get(key)) != value for key, value in SCHEDULE.items() if key != "command"
        ):
            raise Rejected("conflicting_zkaccess_cron")
    return bool(matches)


def install():
    if not inspect():
        cron_call("add_line", SCHEDULE)
    if not inspect():
        raise Rejected("cron_not_installed")
    return True


def main(argv=None):
    parser = argparse.ArgumentParser(description="Guarded independent ZKAccess archive cron")
    parser.add_argument("action", choices=["inspect", "install"])
    args = parser.parse_args(argv)
    try:
        configured = install() if args.action == "install" else inspect()
        print(json.dumps({"ok": True, "worker": "zkaccess-backup",
                          "schedule": "every_5_minutes", "configured": configured}))
        return 0
    except Rejected as e:
        print(json.dumps({"ok": False, "worker": "zkaccess-backup", "error": str(e)}), file=sys.stderr)
        return 1
    except Exception:
        print('{"ok":false,"worker":"zkaccess-backup","error":"provision_failed"}', file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
