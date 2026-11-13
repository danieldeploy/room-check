import json
import os
import pathlib
import sys
import unittest
from email.message import EmailMessage
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / 'deploy'))
import toc_reply_diagnostic as d


class Tests(unittest.TestCase):
    def test_shape_keeps_private_mail_and_identifiers_out_of_report(self):
        m = EmailMessage()
        m['From'] = 'TOConline <no_reply@toconline.pt>'
        m['Subject'] = 'Ficheiros arquivados com sucesso - PRIVATE_COMPANY'
        m.set_content('receipt-' + 'a' * 64 + '.pdf PRIVATE_INVOICE SECRET')
        result = d.message_shape(m.as_bytes(), lambda raw: {'authentication': 'verified', 'outcome': 'toc_review', 'private': 'SECRET'})
        self.assertTrue(result['receipt_identifier'])
        self.assertEqual(result['authentication'], 'verified')
        for value in ('PRIVATE', 'SECRET', 'a' * 64, 'no_reply@', 'arquivados'):
            self.assertNotIn(value, json.dumps(result))

    def test_filter_shape_distinguishes_pipe_destination_and_rule(self):
        filters = [{'filtername': 'SECRET', 'rules': [{'part': '$header_from:', 'match': 'contains', 'val': 'no_reply@toconline.pt'}],
                    'actions': [{'action': 'pipe', 'dest': '|/home/welcome/room-check-private/cron/toconline-reply.php'}, {'action': 'save', 'dest': 'PRIVATE'}]}]
        result = d.filter_shape(filters)
        self.assertTrue(result['sender_rule_found'])
        self.assertTrue(result['correct_pipe_found'])
        self.assertTrue(result['mailbox_copy_action'])
        self.assertNotIn('SECRET', json.dumps(result))
        filters[0]['actions'][0]['dest'] = 'wrong.php'
        self.assertFalse(d.filter_shape(filters)['correct_pipe_found'])

    def test_unrelated_mail_is_never_sent_to_invoice_parser(self):
        m = EmailMessage(); m['From'] = 'private@example.test'; m.set_content('SECRET')
        with patch.object(d, 'verify_message') as verify:
            self.assertIsNone(d.message_shape(m.as_bytes()))
            verify.assert_not_called()

    def test_native_reads_remain_limited_to_selected_mail(self):
        with patch.dict(os.environ, {'WHM_API_TOKEN': 'synthetic-test-only'}):
            api = d.TocMailAPI()
        self.assertEqual(api.allowed_dirs, {d.MAIL_ROOT})
        with patch.object(api.opener, 'open') as opened:
            with self.assertRaises(d.DeploymentError):
                api.call('get_file_content', dir='/home/welcome', file='config.local.php')
            with self.assertRaises(d.DeploymentError):
                api.call('save_file_content', dir=d.MAIL_ROOT, file='x', content='x')
            opened.assert_not_called()

    def test_unknown_authentication_output_is_not_echoed(self):
        m=EmailMessage(); m['From']='no_reply@toconline.pt'; m.set_content('body')
        result=d.message_shape(m.as_bytes(), lambda raw: {'authentication':'SECRET','outcome':'PRIVATE'})
        self.assertEqual(result['authentication'],'unrecognized')
        self.assertEqual(result['outcome'],'unrecognized')

    def test_large_reply_is_classified_without_claiming_authentication(self):
        m=EmailMessage(); m['From']='no_reply@toconline.pt'
        m['Subject']='Ficheiros arquivados com sucesso - Active Lines, Lda'
        m.set_content('receipt-'+ 'a'*64 +'.pdf')
        m.add_attachment(b'x'*300000,maintype='application',subtype='pdf',filename='PRIVATE.pdf')
        with patch.object(d,'verify_message') as verify:
            result=d.message_shape(m.as_bytes())
        verify.assert_not_called()
        self.assertTrue(result['over_pipe_limit'])
        self.assertTrue(result['success_subject'])
        self.assertTrue(result['document_attachment'])
        self.assertEqual(result['authentication'],'not_checked_size')
        self.assertNotIn('PRIVATE',json.dumps(result))

    def test_unknown_reply_exposes_only_fixed_shape_flags(self):
        m=EmailMessage(); m['From']='no_reply@toconline.pt'
        m['Subject']='PRIVATE'; m.set_content('por já constarem no seu arquivo PRIVATE.pdf SECRET')
        result=d.message_shape(m.as_bytes(),lambda raw:{'authentication':'verified','outcome':'toc_review'})
        self.assertTrue(result['duplicate_phrase'])
        self.assertTrue(result['other_document_name'])
        self.assertFalse(result['success_subject'])
        self.assertNotIn('PRIVATE',json.dumps(result))

    def test_delivery_trace_is_filtered_and_does_not_expose_server_messages(self):
        rows=[{'recipient':'123456789@my.toconline.pt','actionunixtime':'300000','type':'failure',
               'message':'550 5.7.1 Message rejected as spam PRIVATE SECRET','sender':'private@example.test'},
              {'recipient':'other@example.test','actionunixtime':'300000','type':'defer','message':'quota'},
              {'recipient':'123456789@my.toconline.pt','actionunixtime':'1','type':'success'}]
        result=d.delivery_shapes(rows,now=300001)
        self.assertEqual(result,[{'state':'failure','smtp_codes':['550'],'enhanced_codes':['5.7.1'],'markers':['spam','message rejected']}])
        for value in ('PRIVATE','SECRET','example','123456789','quota'):
            self.assertNotIn(value,json.dumps(result))

    def test_bounce_shape_finds_exact_original_identifier_without_content(self):
        m=EmailMessage();m['From']='Mail Delivery System <mailer-daemon@example.test>'
        m.set_content('513188118@my.toconline.pt\n550 5.7.1 spam\nMessage-ID: <toc-'+ 'a'*64 +'@check.welcomehostel.pt>\nPRIVATE')
        result=d.message_shape(m.as_bytes())
        self.assertEqual(result['kind'],'delivery_failure')
        self.assertTrue(result['original_identifier'])
        self.assertNotIn('PRIVATE',json.dumps(result))
