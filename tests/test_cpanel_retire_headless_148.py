import contextlib
import io
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_retire_headless_148 as retire


class ObsoleteShellSafetyTests(unittest.TestCase):
    def test_only_148_version_targeted(self):
        self.assertTrue(retire.OLD.endswith("linux-148.0.7778.97"))
        self.assertNotEqual(retire.OLD, retire.CURRENT_SHELL)
        self.assertNotEqual(retire.OLD, retire.CURRENT_CHROME)

    def test_runtime_config_old_shell_blocks_removal(self):
        def read(module, function, params, legacy=False):
            if function == "listcron":
                return {"data": [{"command": "php /home/welcome/room-check-private/cron/cron.php"}]}
            if function == "list_files":
                return [{"file": "invoice-runtime.json", "type": "file"}]
            if function == "get_file_content":
                return {"content": '{"executablePath":"/home/welcome/.cache/puppeteer/chrome-headless-shell/linux-148.0.7778.97/chrome-headless-shell"}'}
            raise AssertionError(function)
        with mock.patch.object(retire, "script_paths_from_crons", return_value=(
                     {"cron_direct_headless_refs":0,"cron_direct_version_refs":0},
                     ["/home/welcome/room-check-private/cron/cron.php"])), \
             mock.patch.object(retire, "safe_read_script", return_value={
                 "status":"read","has_headless_shell":False,
                 "has_executable_path_override":False,"has_nested_includes":True}), \
             mock.patch.object(retire, "request_api", side_effect=read):
            with self.assertRaises(retire.Rejected):
                retire.ensure_no_direct_runtime_reference()

    def test_current_chrome_preserved_and_one_exact_delete_only(self):
        before_items = [
            {"file": "linux-148.0.7778.97", "type": "dir"},
            {"file": "linux-153.0.8010.36", "type": "dir"}
        ]
        after_items = [{"file": "linux-153.0.8010.36", "type": "dir"}]
        snap = {"files": 3, "size_bytes": 52 * retire.MI_B}
        ref = {"cron_scripts_inspected": 5, "nested_include_scripts": 5,
               "runtime_config_present": False, "process_list_available": False}
        calls = []
        def api(module,function,params,legacy=False):
            calls.append((function,params))
            if function == "delete_file":
                self.assertEqual(params,{"path":retire.OLD,"skip_trash":1})
                return None
            raise AssertionError(function)
        directory_calls=[after_items, [{"file":"chrome-linux64","type":"dir"}]]
        def listing(path):
            return {obj["file"]:obj for obj in directory_calls.pop(0)}
        with mock.patch.object(retire, "quota", side_effect=[(4900,6000),(4848,6000)]), \
             mock.patch.object(retire, "old_snapshot", side_effect=[(snap,set()),(snap,set())]), \
             mock.patch.object(retire, "ensure_no_direct_runtime_reference", return_value=ref), \
             mock.patch.object(retire, "directory_files", side_effect=listing), \
             mock.patch.object(retire, "request_api", side_effect=api), \
             contextlib.redirect_stdout(io.StringIO()):
            retire.main(True)
        self.assertEqual([name for name,params in calls], ["delete_file"])

    def test_fail_closed_when_target_changed_before_deletion(self):
        ref={"cron_scripts_inspected":5}
        with mock.patch.object(retire, "quota", return_value=(4900,6000)), \
             mock.patch.object(retire, "old_snapshot", side_effect=[
                 ({"files":3,"size_bytes":52*retire.MI_B},set()),
                 ({"files":3,"size_bytes":51*retire.MI_B},set())]), \
             mock.patch.object(retire, "ensure_no_direct_runtime_reference", return_value=ref), \
             mock.patch.object(retire, "request_api") as api, \
             contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(retire.Rejected):
                retire.main(True)
        api.assert_not_called()

    def test_no_destructive_operation_in_dry_run(self):
        with mock.patch.object(retire, "quota", return_value=(4900,6000)), \
             mock.patch.object(retire, "old_snapshot", return_value=(
                 {"files":3,"size_bytes":52*retire.MI_B},set())), \
             mock.patch.object(retire, "ensure_no_direct_runtime_reference", return_value={
                 "cron_scripts_inspected":5}), \
             mock.patch.object(retire, "request_api") as api, \
             contextlib.redirect_stdout(io.StringIO()):
            retire.main(False)
        api.assert_not_called()


if __name__ == "__main__":
    unittest.main()
