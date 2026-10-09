import subprocess,unittest
class StaticCleanup(unittest.TestCase):
 def source(self):
  return """<?php
$config['db_dsnw']='private-database';
$config['smtp_host']='tls://smtp.postmarkapp.com:587';
$config['smtp_user']='retired-user';
$config['smtp_pass']='retired-password';
$config['plugins'][]='welcome_smtp2go_api';
"""
 def transform(self,source):
  return subprocess.run(['php','deploy/roundcube_remove_legacy_smtp.php'],input=source,text=True,capture_output=True)
 def test_preserves_unrelated_and_removes_credentials(self):
  p=self.transform(self.source());self.assertEqual(p.returncode,0)
  self.assertIn("private-database",p.stdout);self.assertIn("welcome_smtp2go_api",p.stdout)
  self.assertNotIn("retired-password",p.stdout);self.assertNotIn("smtp_host",p.stdout)
 def test_rejects_dynamic_credentials(self):
  p=self.transform(self.source().replace("'retired-password'","getenv('SECRET')"))
  self.assertEqual(p.returncode,2);self.assertEqual(p.stdout,'')
 def test_preserves_same_line_unrelated_setting(self):
  p=self.transform(self.source().replace("$config['smtp_user']='retired-user';","$config['smtp_user']='retired-user'; $config['other']='keep';"))
  self.assertEqual(p.returncode,0);self.assertIn("$config['other']='keep';",p.stdout)
if __name__=='__main__':unittest.main()
