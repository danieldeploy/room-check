import datetime
import pathlib
import sys
import unittest
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / 'deploy'))
try:
    from cpanel_log_compaction_report import sanitize
except ImportError:
    sanitize = None

class ReportTests(unittest.TestCase):
    def test_sanitizes_private_projection(self):
        self.assertIsNotNone(sanitize, 'compaction report validation is available')
        p = {'schema':'whm-log-compaction-v1','ok':True,'historical_bytes_preserved':True,
             'files_compacted':1,'bytes_reclaimed':500,'profiles':[{
                 'size_bytes':900,'age_days':400,'status':'compacted','lines':9,
                 'origin_counts':{'roundcube':9},'severity_counts':{'fatal':9},
                 'first_date':'2019-01-01','last_date':'2019-01-02',
                 'secret':'NEVER_EXPORTED'}]}
        out = sanitize(p)
        self.assertEqual(out['bytes_reclaimed'], 500)
        self.assertNotIn('NEVER_EXPORTED', str(out))
        for key, value in [('first_date','PRIVATE'),('status','arbitrary_path'),
                           ('origin_counts',{'guest@example.org':2}),('size_bytes',-1)]:
            bad = {**p, 'profiles':[{**p['profiles'][0], key:value}]}
            with self.assertRaises(Exception): sanitize(bad)
        with self.assertRaises(Exception): sanitize({**p, 'historical_bytes_preserved':False})

if __name__ == '__main__': unittest.main()
