import importlib.util
import pathlib
import sys
import unittest
from unittest import mock

DEPLOY = pathlib.Path(__file__).resolve().parents[1] / "deploy"
sys.path.insert(0, str(DEPLOY))
spec = importlib.util.spec_from_file_location("cleanup_auto", DEPLOY / "cpanel_auto_cleanup.py")
auto = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auto)


class AutomaticCleanupTests(unittest.TestCase):
    def test_below_threshold_never_deletes(self):
        with mock.patch.object(auto, "quota", return_value=(5000, 6000)), mock.patch.object(auto, "delete_exact") as deleting:
            auto.main()
            deleting.assert_not_called()

    def test_over_quota_only_bounded_candidates(self):
        with mock.patch.object(auto, "quota", side_effect=[(6001, 6000), (5900, 6000)]), \
                mock.patch.object(auto, "candidates", return_value=iter([(".npm/_cacache/a", 100, 50)])), \
                mock.patch.object(auto, "delete_exact", return_value={"bytes": 100, "state": "permanently_deleted"}) as deleting:
            auto.main()
            deleting.assert_called_once_with(".npm/_cacache/a", 100, 50)

    def test_quota_invalid_fails_closed(self):
        with mock.patch.object(auto, "request_api", return_value={"diskused": 6000}):
            with self.assertRaises(auto.Rejected):
                auto.quota()

    def test_dangerous_paths_never_candidates(self):
        with mock.patch.object(auto, "list_files", return_value=[
            {"file": "unauthorized", "type": "link", "size": 900, "mtime": 1}]):
            self.assertEqual(list(auto.candidates(2000000)), [])


if __name__ == "__main__":
    unittest.main()
