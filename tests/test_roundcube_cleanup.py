import base64
import os
import pathlib
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_cleanup as cleanup


def b64(value):
    return base64.b64encode(value.encode()).decode()


class RoundcubeCleanupTests(unittest.TestCase):
    def test_extracts_current_serial_and_only_exact_postmark_cname(self):
        rows = [
            {'type': 'record', 'line_index': 3, 'ttl': 14400, 'record_type': 'SOA',
             'dname_b64': b64('welcomehostel.pt.'),
             'data_b64': [b64('ns1.example.'), b64('hostmaster.example.'), b64('2026100501'),
                          b64('3600'), b64('1800'), b64('1209600'), b64('86400')]},
            {'type': 'record', 'line_index': 76, 'ttl': 14400, 'record_type': 'CNAME',
             'dname_b64': b64('pm-bounces'), 'data_b64': [b64('pm.mtasv.net.')]},
            {'type': 'record', 'line_index': 77, 'ttl': 3600, 'record_type': 'CNAME',
             'dname_b64': b64('em1063225'), 'data_b64': [b64('return.smtp2go.net.')]},
        ]
        serial, found = cleanup._target_dns(rows)
        self.assertEqual(serial, 2026100501)
        self.assertEqual(len(found), 1)
        self.assertEqual(found[0]['line_index'], 76)
        self.assertEqual(found[0]['ttl'], 14400)

    def test_refuses_duplicate_postmark_records(self):
        rows = [
            {'type': 'record', 'line_index': 3, 'ttl': 14400, 'record_type': 'SOA',
             'dname_b64': b64('welcomehostel.pt.'),
             'data_b64': [b64('ns1'), b64('hostmaster'), b64('2026100501')]},
        ]
        rows.extend([
            {'type': 'record', 'line_index': n, 'ttl': 14400, 'record_type': 'CNAME',
             'dname_b64': b64('pm-bounces'), 'data_b64': [b64('pm.mtasv.net.')]}
            for n in (76, 77)
        ])
        with self.assertRaisesRegex(cleanup.CleanupError, 'duplicate_postmark_record'):
            cleanup._target_dns(rows)

    def test_rejects_invalid_or_ambiguous_soa_serial(self):
        rows = [{'type': 'record', 'line_index': 3, 'ttl': 14400, 'record_type': 'SOA',
                 'dname_b64': b64('welcomehostel.pt.'),
                 'data_b64': [b64('ns1'), b64('hostmaster'), b64('not-a-serial')]}]
        with self.assertRaisesRegex(cleanup.CleanupError, 'invalid_zone_serial'):
            cleanup._target_dns(rows)
        rows[0]['data_b64'][2] = b64('2026100501')
        rows.append(dict(rows[0]))
        with self.assertRaisesRegex(cleanup.CleanupError, 'zone_serial_not_unique'):
            cleanup._target_dns(rows)

    def test_test_targets_exclude_live_configuration_and_backup(self):
        targets = set(cleanup.TRASH_PATHS)
        self.assertEqual(len(targets), len(cleanup.TRASH_PATHS))
        self.assertFalse(any(path.endswith('welcome_smtp2go_api') for path in targets))
        self.assertFalse(any('config-before-production' in path for path in targets))
        self.assertFalse(any(path.endswith('/live') for path in targets))
        self.assertFalse(any(path.endswith('dns-record-rollback-20261005.json') for path in targets))

    def test_confirmation_guard_stops_before_api_client(self):
        with patch.dict(os.environ, {'CONFIRM_CLEANUP': ''}, clear=False), \
             patch.object(cleanup, 'Client') as client:
            self.assertEqual(cleanup.main(), 2)
            client.assert_not_called()


if __name__ == '__main__':
    unittest.main()
