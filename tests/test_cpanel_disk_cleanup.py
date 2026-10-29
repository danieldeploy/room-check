import importlib.util
import pathlib
import unittest
from unittest import mock

FILE = pathlib.Path(__file__).resolve().parents[1] / "deploy" / "cpanel_disk_cleanup.py"
spec = importlib.util.spec_from_file_location("cleanup", FILE)
cleanup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cleanup)


class CleanupTests(unittest.TestCase):
    def test_only_explicit_npm_cache_file(self):
        self.assertEqual(cleanup.safe_file(".npm/_cacache/content-v2/sha512/abc"),
                         ".npm/_cacache/content-v2/sha512/abc")
        for accepted in (".cache/pip/http-v2/a", ".cache/uv/archive/a", ".cache/puppeteer/chrome-headless-shell/148.0.7778.97-chrome-headless-shell-linux64.zip"):
            self.assertEqual(cleanup.safe_file(accepted), accepted)
        for value in (".cache/puppeteer/chrome-headless-shell/linux-148.0.7778.97/chrome", "mail/a", "room-check-backups/a", ".cache/a", "public_html/index.php",
                      ".npm/_cacache/", ".npm/_cacache/../../mail/a",
                      "/home/welcome/.npm/_cacache/x", ".npm/_cacache/a,b"):
            with self.subTest(value=value), self.assertRaises(cleanup.Rejected):
                cleanup.safe_file(value)

    def test_no_trash_if_file_changed(self):
        with mock.patch.object(cleanup, "inspect", return_value={"path": ".npm/_cacache/x",
                         "size": 10, "mtime": 20}), mock.patch.object(cleanup, "request_api") as api:
            with self.assertRaises(cleanup.Rejected):
                cleanup.delete_exact(".npm/_cacache/x", 9, 20)
            api.assert_not_called()

    def test_trash_exact_file_only(self):
        with mock.patch.object(cleanup, "inspect", return_value={"path": ".npm/_cacache/x",
                         "size": 10, "mtime": 20}), mock.patch.object(cleanup, "request_api") as api:
            self.assertEqual(cleanup.delete_exact(".npm/_cacache/x", 10, 20)["state"],
                             "permanently_deleted")
            api.assert_called_once_with("Fileman", "delete_file",
                {"path": ".npm/_cacache/x", "skip_trash": 1})


if __name__ == "__main__":
    unittest.main()
