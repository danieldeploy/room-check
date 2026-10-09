import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_verify as verify


class VerificationTests(unittest.TestCase):
    def events(self):
        return [
            {'state': 'attempt_started', 'time_utc': '2026-10-04T13:31:13+00:00'},
            {'state': 'accepted', 'time_utc': '2026-10-04T13:31:14+00:00',
             'http': 200, 'curl_errno': 0, 'email_id': 'private-test-id'},
        ]

    def encoded(self, events):
        return '\n'.join(json.dumps(x) for x in events) + '\n'

    def test_acceptance_does_not_publish_private_identifier(self):
        result = verify.summarize(self.encoded(self.events()))
        self.assertEqual(result['state'], 'accepted')
        self.assertTrue(result['inside_sandbox_window'])
        self.assertNotIn('private-test-id', json.dumps(result))

    def test_uncertain_attempt_is_not_accepted(self):
        self.assertEqual(verify.summarize(self.encoded(self.events()[:1]))['state'], 'attempt_started')

    def test_invalid_result_is_rejected(self):
        for field, value in [('http', 500), ('curl_errno', 28), ('email_id', ''), ('time_utc', '2026-10-04T12:00:00Z')]:
            events = self.events()
            events[1][field] = value
            with self.subTest(field=field), self.assertRaises(verify.base.PreparationError):
                verify.summarize(self.encoded(events))

    def test_extra_data_is_never_published(self):
        events = self.events()
        events[1]['body'] = 'private'
        with self.assertRaises(verify.base.PreparationError):
            verify.summarize(self.encoded(events))

    def test_routes_deny_writes_secrets_and_undiscovered_journals(self):
        client = verify.Client('test-token')
        with patch.object(client.opener, 'open') as request:
            for op, args in [('write', {'path': verify.activation.TARGET, 'content': ''}),
                             ('chmod', {'path': verify.base.BACKUP}),
                             ('read', {'path': verify.activation.KEY}),
                             ('read', {'path': verify.base.CONFIG}),
                             ('read', {'path': verify.base.PRIVATE + '/' + 'a' * 64 + '.json'}),
                             ('list', {'directory': '/home/welcome'})]:
                with self.subTest(op=op, args=args), self.assertRaises(verify.base.PreparationError):
                    client.call(op, **args)
            request.assert_not_called()

    def test_traversal_is_rejected_even_if_in_discovered_set(self):
        client = verify.Client('test-token')
        path = verify.base.PRIVATE + '/../sandbox-key.txt'
        client.journals = {path}
        with self.assertRaises(verify.base.PreparationError):
            client.call('read', path=path)


if __name__ == '__main__':
    unittest.main()
