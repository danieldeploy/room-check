import contextlib
import io
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_log_access_probe as probe


class ScopedLogPermissionProbeTests(unittest.TestCase):
    def test_probe_does_not_delete_or_print_filename(self):
        name = "logs/private-domain-error_log"
        def response(module, function, params, legacy=False):
            if function == "get_file_information":
                return {"type": "file", "size": 34 * 1024 * 1024, "mtime": 100}
            if function == "statfiles":
                return {"data": [{"file": "private-domain-error_log", "type": "file",
                                  "user": "welcome", "mode": 33188}]}
            if function == "list_files":
                return [{"file": "private-domain-error_log", "type": "file"}]
            raise AssertionError("Unexpected API function " + function)
        output = io.StringIO()
        with mock.patch.object(probe, "snapshot", return_value=([], [(name, 34 * 1024 * 1024, 100)])), \
             mock.patch.object(probe, "request_api", side_effect=response) as api, \
             contextlib.redirect_stdout(output):
            probe.probe()
        functions = [c.args[1] for c in api.call_args_list]
        self.assertEqual(functions.count("get_file_information"), 2)
        self.assertEqual(functions.count("statfiles"), 1)
        self.assertEqual(functions.count("list_files"), 1)
        self.assertNotIn("private-domain-error_log", output.getvalue())

    def test_missing_candidate_stops_without_file_api_calls(self):
        with mock.patch.object(probe, "snapshot", return_value=([], [])), \
             mock.patch.object(probe, "request_api") as api:
            probe.probe()
        api.assert_not_called()


if __name__ == "__main__":
    unittest.main()
