import datetime as dt
import hashlib
import importlib.util
import pathlib
import sys
import unittest
from unittest import mock

DEPLOY = pathlib.Path(__file__).resolve().parents[1] / "deploy"
sys.path.insert(0, str(DEPLOY))
spec = importlib.util.spec_from_file_location("retention", DEPLOY / "cpanel_backup_retention.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def release(day, hour, marker):
    return "release-" + day + "-" + hour + "-" + marker * 12 + "-" + marker * 12


class RetentionTests(unittest.TestCase):
    def test_keep_daily_and_three_latest_and_oldest(self):
        now = dt.datetime(2026, 11, 2, 22, tzinfo=dt.timezone.utc)
        names = sorted([
            release("20261028", "100000", "a"),
            release("20261028", "110000", "b"),
            release("20261028", "120000", "c"),
            release("20261028", "130000", "d"),
            release("20261028", "140000", "e"),
            release("20261025", "100000", "f"),
            release("20261019", "100000", "0"),
        ], reverse=True)
        keep, candidates = m.plan(names, now)
        self.assertIn(names[0], keep)
        self.assertIn(names[-1], keep)
        self.assertEqual(len(candidates), 2)
        self.assertTrue(all(x not in keep for x in candidates))

    def test_recent_versions_cannot_be_deleted(self):
        now = dt.datetime(2026, 10, 29, 22, tzinfo=dt.timezone.utc)
        names = sorted([release("20261029", h, n) for h, n in
             [("080000","a"),("090000","b"),("100000","c"),
              ("110000","d"),("120000","e")]], reverse=True)
        _, candidates = m.plan(names, now)
        self.assertEqual(candidates, [])

    def test_invalid_names_fail_closed(self):
        for name in ("mail/foo", "../release-20261001-100000-aa",
                     "release-20261031-990000-" + "a"*12 + "-" + "a"*12):
            with self.subTest(name=name), self.assertRaises(m.Rejected):
                m.release_time(name)

    def test_manifest_needs_sql_and_app_regular_files(self):
        name = release("20261025", "100000", "a")
        digest = "a" * 64
        rows = [{"file":"complete.json", "type":"file", "size": 500},
                {"file":"database.sql", "type":"file", "size": 200},
                {"file":"application.tar.gz", "type":"link", "size": 1000}]
        data = {"format":1, "sha256":{"database.sql":digest, "application.tar.gz":digest}}
        with mock.patch.object(m, "listing", return_value={x["file"]:x for x in rows}), \
                mock.patch.object(m, "content", return_value=__import__("json").dumps(data).encode()):
            with self.assertRaises(m.Rejected):
                m.verified_manifest(name)

    def test_restore_checksum_precedes_database_import(self):
        name = release("20261025", "100000", "a")
        sql = b"CREATE TABLE t (id INT);"
        data = {"format":1,"sha256":{"database.sql": hashlib.sha256(sql).hexdigest()}}
        with mock.patch.object(m, "verified_manifest", return_value=(data,300)), \
             mock.patch.object(m, "content", return_value=sql), \
             mock.patch.object(m, "restore_sql_in_ephemeral_mysql") as restore:
            m.restore_latest_backup(name)
            restore.assert_called_once_with(sql)
        data["sha256"]["database.sql"] = "f" * 64
        with mock.patch.object(m, "verified_manifest", return_value=(data,300)), \
             mock.patch.object(m, "content", return_value=sql), \
             mock.patch.object(m, "restore_sql_in_ephemeral_mysql") as restore:
            with self.assertRaises(m.Rejected):
                m.restore_latest_backup(name)
            restore.assert_not_called()

    def test_no_delete_in_dry_run(self):
        names = sorted([
            release("20261025", "080000","a"), release("20261025", "090000","b"),
            release("20261025", "100000","c"), release("20261025", "110000","d"),
            release("20261025", "120000","e")
        ], reverse=True)
        with mock.patch.object(m, "releases", return_value=names), \
             mock.patch.object(m, "verified_manifest", return_value=({"format":1},300)), \
             mock.patch.object(m, "restore_latest_backup"), \
             mock.patch.object(m, "request_api") as api:
            m.process(False, dt.datetime(2026,10,29,tzinfo=dt.timezone.utc))
            api.assert_not_called()

    def test_apply_never_deletes_kept_and_rechecks_roster(self):
        now = dt.datetime(2026, 10, 29, 22, tzinfo=dt.timezone.utc)
        actual = sorted([
            release("20261020", "080000","a"),release("20261020", "090000","b"),
            release("20261020", "100000","c"),release("20261020", "110000","d"),
            release("20261020", "120000","e"),release("20261019", "080000","f"),
        ], reverse=True)
        keep, remove = m.plan(actual, now)
        deleted = []
        def api(mod, func, params):
            self.assertEqual((mod, func), ("Fileman", "delete_file"))
            self.assertEqual(params["skip_trash"], 1)
            name = params["path"].rsplit("/",1)[-1]
            self.assertIn(name, remove)
            self.assertNotIn(name, keep)
            actual.remove(name)
            deleted.append(name)
            return {}
        with mock.patch.object(m, "releases", side_effect=lambda: list(actual)), \
             mock.patch.object(m, "verified_manifest", return_value=({"format":1},1024)), \
             mock.patch.object(m, "restore_latest_backup"), \
             mock.patch.object(m, "request_api", side_effect=api):
            m.process(True, now)
        self.assertEqual(deleted, remove)
        self.assertTrue(keep.issubset(actual))

    def test_change_during_run_aborts(self):
        now = dt.datetime(2026,10,29,tzinfo=dt.timezone.utc)
        initial = sorted([release("20261020",h,n) for h,n in
            [("080000","a"),("090000","b"),("100000","c"),("110000","d"),
             ("120000","e")]],reverse=True)
        changes = [initial, initial + [release("20261029","110000","f")]]
        with mock.patch.object(m, "releases", side_effect=changes), \
             mock.patch.object(m, "verified_manifest", return_value=({"format":1},100)), \
             mock.patch.object(m, "restore_latest_backup"), \
             mock.patch.object(m, "request_api") as api:
            with self.assertRaises(m.Rejected):
                m.process(True, now)
            api.assert_not_called()


if __name__ == "__main__":
    unittest.main()
