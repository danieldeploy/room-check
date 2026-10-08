import copy
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_citycenter_smtp2go_dns as dns


class FakeClient:
    def __init__(self, records=None, fail_on_add=None):
        self.records = copy.deepcopy(records or [])
        self.fail_on_add = fail_on_add
        self.events = []

    def account_for_domain(self):
        self.events.append('resolve')
        return 'ccenter'

    def dns_records(self):
        self.events.append('read_dns')
        return copy.deepcopy(self.records)

    def ensure_private_backup(self, records):
        self.events.append(('backup', copy.deepcopy(records)))
        return '/home/ccenter/roundcube-smtp2go-citycenter-private/dns-before-smtp2go-20261008.json'

    def add_cname(self, rec):
        self.events.append(('add', rec['name']))
        if self.fail_on_add == rec['name']:
            raise dns.OperationError('dns_add_failed')
        self.records.append({'name': rec['name'], 'type': 'CNAME', 'data': [rec['target']],
                             'ttl': rec['ttl'], 'line_index': 100 + len(self.records)})

    def remove_line(self, line_index):
        self.events.append(('remove', line_index))
        self.records = [r for r in self.records if r['line_index'] != line_index]


class CityCenterSmtp2goDnsTests(unittest.TestCase):
    def test_legacy_api2_response_is_parsed_without_whm_metadata(self):
        class Response:
            status = 200
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def geturl(self): return self.url
            def read(self, limit):
                if '/uapi_cpanel?' in self.url:
                    return json.dumps({'metadata': {'result': 1}, 'data': {'uapi': {'status': 1, 'errors': None, 'data': {'files': [{'file': 'public_html'}]}}}}).encode()
                return json.dumps({'cpanelresult': {'event': {'result': 1}, 'data': [{'exists': 1, 'nicemode': '0700', 'type': 'dir'}]}}).encode()
        class Opener:
            def open(self, request, timeout):
                response = Response()
                response.url = request.full_url
                return response
        client = dns.Client('test-token', opener=Opener())
        client.user = 'ccenter'
        self.assertEqual(client.file_stat('/home/ccenter', 'public_html')['type'], 'dir')

    def test_missing_backup_is_detected_without_legacy_stat_error(self):
        client = dns.Client('test-token')
        client.user = 'ccenter'
        client.file_inventory = lambda directory: set()
        client.api2 = lambda *args, **kwargs: self.fail('Must not stat a missing file')
        self.assertIsNone(client.file_stat('/home/ccenter', 'missing-backup.json'))

    def test_adds_fixed_records_after_private_backup_and_emits_aggregate_only(self):
        client = FakeClient([{'name': 'citycenterhostel.pt', 'type': 'MX', 'data': ['mail.example'],
                              'ttl': 3600, 'line_index': 1}])
        report = dns.apply(client)
        self.assertTrue(report['ok'])
        self.assertEqual(report['records_added'], 2)
        self.assertEqual(report['records_present'], 2)
        backup_index = next(i for i, event in enumerate(client.events) if isinstance(event, tuple) and event[0] == 'backup')
        first_add = next(i for i, event in enumerate(client.events) if isinstance(event, tuple) and event[0] == 'add')
        self.assertLess(backup_index, first_add)
        self.assertEqual(report['email_sent'], False)
        self.assertNotIn('em1063225.citycenterhostel.pt', str(report))
        self.assertNotIn('dkim.smtp2go.net', str(report))

    def test_is_idempotent_when_fixed_records_already_exist(self):
        client = FakeClient([
            {'name': rec['name'], 'type': 'CNAME', 'data': [rec['target']],
             'ttl': rec['ttl'], 'line_index': i + 1} for i, rec in enumerate(dns.RECORDS)
        ])
        report = dns.apply(client)
        self.assertEqual(report['records_added'], 0)
        self.assertEqual(report['records_present'], 2)
        self.assertEqual(len([e for e in client.events if isinstance(e, tuple) and e[0] == 'add']), 0)

    def test_conflicting_name_fails_before_backup_or_write(self):
        rec = dns.RECORDS[0]
        client = FakeClient([{'name': rec['name'], 'type': 'CNAME', 'data': ['wrong.example'],
                              'ttl': 3600, 'line_index': 9}])
        with self.assertRaisesRegex(dns.OperationError, 'dns_name_conflict'):
            dns.apply(client)
        self.assertFalse(any(isinstance(e, tuple) and e[0] in {'backup', 'add'} for e in client.events))

    def test_partial_write_is_rolled_back(self):
        client = FakeClient(fail_on_add=dns.RECORDS[1]['name'])
        with self.assertRaisesRegex(dns.OperationError, 'dns_add_failed'):
            dns.apply(client)
        self.assertEqual(client.records, [])
        self.assertEqual(len([e for e in client.events if isinstance(e, tuple) and e[0] == 'remove']), 1)


if __name__ == '__main__':
    unittest.main()
