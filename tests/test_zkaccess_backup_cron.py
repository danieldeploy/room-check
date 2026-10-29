import pathlib
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import zkaccess_backup_cron as backup


def entry(**overrides):
    return dict(backup.SCHEDULE, **overrides)


class IndependentZKAccessCronTests(unittest.TestCase):
    def test_only_fixed_5_minute_job_is_allowed(self):
        self.assertIn("/home/welcome/room-check-private/cron/zkaccess-backups.php", backup.COMMAND)
        self.assertEqual(backup.SCHEDULE["minute"], "*/5")
        self.assertNotIn("invoices.php", backup.COMMAND)
        self.assertNotIn("room_check_invoices", backup.COMMAND)
        with patch.object(backup, "request_api") as transport:
            for params in [dict(backup.SCHEDULE, command="php /tmp/untrusted.php"),
                           dict(backup.SCHEDULE, hour="4")]:
                with self.assertRaises(backup.Rejected):
                    backup.cron_call("add_line", params)
            with self.assertRaises(backup.Rejected):
                backup.cron_call("edit_line", backup.SCHEDULE)
            with self.assertRaises(backup.Rejected):
                backup.cron_call("listcron", {"username": "root"})
            transport.assert_not_called()

    def test_existing_exact_cron_is_idempotent(self):
        with patch.object(backup, "cron_call", return_value=[entry(), {"count": 2}]) as call:
            self.assertTrue(backup.install())
            self.assertEqual([item.args[0] for item in call.call_args_list],
                             ["listcron", "listcron"])

    def test_install_and_verify_once(self):
        jobs = []

        def fake(action, parameters=None):
            if action == "listcron":
                return jobs + [{"count": len(jobs) + 1}]
            if action == "add_line":
                self.assertEqual(parameters, backup.SCHEDULE)
                jobs.append(entry())
                return [{"status": 1}]
            self.fail("unapproved command")

        with patch.object(backup, "cron_call", side_effect=fake) as transport:
            self.assertTrue(backup.install())
            self.assertTrue(backup.inspect())
            self.assertEqual([c.args[0] for c in transport.call_args_list].count("add_line"), 1)

    def test_wrong_cron_or_duplicate_aborts_without_mutation(self):
        for rows in [[entry(command="php /tmp/zkt --zkaccess-backups.php")],
                     [entry(minute="*")],
                     [entry(), entry()]]:
            with patch.object(backup, "cron_call", return_value=rows) as transport:
                with self.assertRaises(backup.Rejected):
                    backup.install()
                transport.assert_called_once_with("listcron")

    def test_api2_response_is_validated(self):
        with patch.object(backup, "request_api", return_value={"data": [{"status": 0}]}):
            with self.assertRaises(backup.Rejected):
                backup.cron_call("add_line", backup.SCHEDULE)
        with patch.object(backup, "request_api", return_value={"data": None}):
            with self.assertRaises(backup.Rejected):
                backup.cron_call("listcron")


if __name__ == "__main__":
    unittest.main()
