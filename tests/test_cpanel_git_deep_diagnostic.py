import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_git_deep_diagnostic as diag


class DeepCloneDiagnosisTests(unittest.TestCase):
    @staticmethod
    def fixture():
        return {"truncated": False, "tree": [
            {"type": "blob", "path": "docs/example.md", "size": 42},
            {"type": "blob", "path": "docs/help.md", "size": 100},
            {"type": "blob", "path": "deploy/release.sh", "size": 180},
            {"type": "tree", "path": "docs"},
            *({"type": "blob", "path": "tests/a%03d.php" % i, "size": 10}
              for i in range(100)),
        ]}

    def test_detects_nested_untracked_and_modified_tracked_sizes(self):
        files = diag.paths_from_tree(self.fixture())
        directory = diag.ROOT + "/docs"
        observed = diag.scan_response("docs", [
            {"file": directory, "type": "dir", "size": 4096},
            {"file": directory + "/example.md", "type": "file", "size": 70},
            {"file": directory + "/new/extra.txt", "type": "file", "size": 4},
            {"file": directory + "/new", "type": "dir", "size": 4096},
        ])
        result = diag.compare_section("docs", files, observed)
        self.assertEqual(result["different_size"], 1)
        self.assertEqual(result["missing"], 1)
        self.assertEqual(result["untracked"], 1)
        self.assertEqual(result["links"], 0)
        self.assertEqual(result["seen"], 2)
        self.assertNotIn("example.md", str(result))
        self.assertNotIn("extra.txt", str(result))

    def test_rejects_outside_server_root_and_repeated_path(self):
        for rows in [
            [{"file": "/home/city/private", "type": "file", "size": 42}],
            [{"file": diag.ROOT + "/docs/../../secrets", "type": "file", "size": 42}],
            [{"file": diag.ROOT + "/docs/a.txt", "type": "file", "size": 5},
             {"file": diag.ROOT + "/docs/a.txt", "type": "file", "size": 5}],
        ]:
            with self.assertRaises(diag.Rejected):
                diag.scan_response("docs", rows)

    def test_refuses_truncated_installed_tree_or_malformed_records(self):
        tree = self.fixture()
        tree["truncated"] = True
        with self.assertRaises(diag.Rejected):
            diag.paths_from_tree(tree)
        with self.assertRaises(diag.Rejected):
            diag.scan_response("docs", [{"file": diag.ROOT + "/docs/x", "size": "NaN", "type": "file"}])
        with self.assertRaises(diag.Rejected):
            diag.scan_response("docs", [{"file": diag.ROOT + "/docs/x", "size": 1, "type": "socket"}])


if __name__ == "__main__":
    unittest.main()
