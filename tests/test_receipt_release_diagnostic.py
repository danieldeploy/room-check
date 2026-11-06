import sys,unittest,json
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'deploy'))
from receipt_release_diagnostic import summarize_log,configured_node
class ReceiptReleaseDiagnostic(unittest.TestCase):
    def test_no_raw_config_or_log_output(self):
        text='secret-token\n{"ok":false,"receipt_pdf_split":"failed","error":"receipt_split_unavailable","password":"secret-token"}\n'
        result=summarize_log(text)
        self.assertEqual(result,{'classifications':['receipt_split_unavailable'],'receipt_pdf_split':'failed'})
        self.assertNotIn('secret-token',json.dumps(result))
    def test_literal_node_only(self):
        self.assertEqual(configured_node("'password'=>'secret', 'node_binary'=>'/opt/cpanel/ea-nodejs22/bin/node'"),'/opt/cpanel/ea-nodejs22/bin/node')
        for text in ["'node_binary'=>getenv('SECRET')","'node_binary'=>'https://bad/node'","'node_binary'=>'/home/welcome/../secret/node'"]:
            self.assertIsNone(configured_node(text))
    def test_only_read_operations(self):
        text=(Path(__file__).resolve().parents[1]/'deploy/receipt_release_diagnostic.py').read_text()
        self.assertNotIn('save_file_content',text)
        self.assertNotIn("'create'",text)
        self.assertNotIn("'update'",text)
if __name__=='__main__':unittest.main()
