import base64
import hashlib
import io
import json
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_disk_cleanup as api
import cpanel_git_tracked_repair as repair


class TrackedRepairTests(unittest.TestCase):
    def test_trusted_blob_matches_git_tree_exactly(self):
        data = b"name: original-ci\non: push\n"
        blob = hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()
        tree = {"truncated": False, "tree": [
            {"path": ".github/workflows/ci.yml", "type": "blob",
             "sha": blob, "size": len(data)}]}
        github = mock.Mock()
        github.read.return_value = {"sha": blob, "encoding": "base64",
                                    "content": base64.b64encode(data).decode()}
        self.assertEqual(repair.expected_bytes(github, tree, ".github/workflows/ci.yml"), data)
        github.read.return_value["content"] = base64.b64encode(b"modified").decode()
        with self.assertRaises(api.Rejected):
            repair.expected_bytes(github, tree, ".github/workflows/ci.yml")

    def test_only_two_safe_regular_extras(self):
        tree = {"truncated": False, "tree": [
            {"path": ".github/workflows/ci.yml", "type": "blob"}]}
        rows = {
            repair.ROOT + "/.github": {
                "workflows": {"type": "dir"}, "extra-1.yml": {"type": "file"}},
            repair.ROOT + "/.github/workflows": {
                "ci.yml": {"type": "file"}, "extra-2.yml": {"type": "file"}},
        }
        with mock.patch.object(repair, "files_in", side_effect=lambda path: rows[path]), \
                mock.patch.object(repair, "file_info", return_value={"size": 42, "mtime": 10}):
            result = repair.extra_entries(tree)
        self.assertEqual(len(result), 2)
        self.assertTrue(all(x[1].startswith(repair.ROOT + "/.github/") for x in result))
        rows[repair.ROOT + "/.github/workflows"]["extra-2.yml"]["type"] = "dir"
        with mock.patch.object(repair, "files_in", side_effect=lambda path: rows[path]), \
                mock.patch.object(repair, "file_info", return_value={"size": 42, "mtime": 10}):
            with self.assertRaises(api.Rejected):
                repair.extra_entries(tree)

    def test_previous_private_quarantine_blocks_replay(self):
        state = {"repository_root": "test", "branch": repair.BRANCH,
                 "repository_busy": False, "deployable": False,
                 "current_commit": repair.OLD, "last_deployed_commit": repair.OLD}
        runner = mock.Mock()
        runner.status.return_value = state
        # A changed server binding must abort without calling the mutation API.
        with mock.patch.object(repair, "Config", return_value=mock.Mock(repository="other")), \
             mock.patch.object(repair, "Deployment", return_value=runner), \
             mock.patch.object(repair, "request_api") as called:
            with self.assertRaises(api.Rejected):
                repair.safe_preflight()
        called.assert_not_called()

    def test_post_body_not_in_whm_url(self):
        content = "public tracked text, never placed in the URL"
        response = {"metadata": {"result": 1},
                    "data": {"uapi": {"status": 1, "errors": None,
                                      "data": {"path": "tracked.txt"}}}}
        opened = []
        class Resp:
            status = 200
            def __init__(self, url): self.url = url
            def geturl(self): return self.url
            def read(self, n): return json.dumps(response).encode()
            def __enter__(self): return self
            def __exit__(self, *args): pass
        class Opener:
            def open(self, req, timeout):
                opened.append(req)
                return Resp(req.full_url)
        with mock.patch.dict("os.environ", {"WHM_API_TOKEN": "placeholder-token"}), \
             mock.patch.object(api.urllib.request, "build_opener", return_value=Opener()):
            api.request_api("Fileman", "save_file_content",
                            {"dir": "/home/welcome/repositories", "file": "tracked.txt",
                             "content": content}, form=True)
        self.assertEqual(len(opened), 1)
        self.assertNotIn("public%20tracked", opened[0].full_url)
        self.assertNotIn(content, opened[0].full_url)
        self.assertIn(b"content=", opened[0].data)
        self.assertEqual(opened[0].get_method(), "POST")

    def test_form_does_not_allow_arbitrary_operations(self):
        with mock.patch.dict("os.environ", {"WHM_API_TOKEN": "placeholder-token"}):
            with self.assertRaises(api.Rejected):
                api.request_api("Fileman", "delete_file", {"path": "mail"}, form=True)

    def test_already_clean_preflight_requires_no_file_actions(self):
        cfg = mock.Mock(repository=repair.ROOT)
        runner = mock.Mock()
        runner.status.return_value = {
            "repository_root": repair.ROOT, "branch": repair.BRANCH,
            "repository_busy": False, "deployable": True}
        with mock.patch.object(repair, "Config", return_value=cfg), \
             mock.patch.object(repair, "Deployment", return_value=runner), \
             mock.patch.object(repair, "request_api") as api_call:
            self.assertEqual(repair.safe_preflight()["state"], "already_clean")
        api_call.assert_not_called()


if __name__ == "__main__":
    unittest.main()
