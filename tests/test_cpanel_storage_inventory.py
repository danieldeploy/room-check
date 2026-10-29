import datetime as dt
import sys
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_storage_inventory as inv

class StorageInventoryTests(unittest.TestCase):
    def test_protect_recent_and_unknown(self):
        now=dt.datetime(2026,10,29,tzinfo=dt.timezone.utc)
        rows=[{"file":"release-20261028-121000-abc","type":"dir"},
              {"file":"release-20260101-121000-abc","type":"dir"},
              {"file":"release-unknown","type":"dir"}]
        result=inv.classify_backups(rows,now)
        self.assertEqual(result["within_45_days"],1)
        self.assertEqual(result["older_than_45_days"],1)
        self.assertEqual(result["unparseable_names"],1)
        self.assertFalse(result["deletion_authorized"])
if __name__=="__main__": unittest.main()
