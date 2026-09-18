<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Invoices/InvoiceDrive.php';
$tmp = sys_get_temp_dir().'/drive-test-'.bin2hex(random_bytes(6)); mkdir($tmp,0700);
file_put_contents($tmp.'/master.key',random_bytes(32)); chmod($tmp.'/master.key',0600);
$vault=new InvoiceVault($tmp);
$accounts=new InvoiceAccounts($pdo);
$account=$accounts->create('airbnb','Test Airbnb',['account'=>'Test Airbnb'],'password');
$job=['id'=>1,'account_id'=>$account,'property_id'=>'account','period'=>'2026-08'];
$content="Invoice,Company\nINV-12,Active Lines\n";
$service->import($vault,$job,['number'=>'csv-2026-08','period'=>'2026-08','period_basis'=>'export_month','format'=>'csv','content'=>base64_encode($content)]);
$doc=(int)$pdo->query('SELECT MAX(id) FROM invoice_documents')->fetchColumn();
$pdo->exec("UPDATE invoice_drive_settings SET folder_id='testroot00000001' WHERE id=1");
$client=new class {
 public bool $fail=true; public bool $corrupt=false; public array $uploads=[]; private int $counter=0;
 public function connect(): string {return 'daniel.ciorcas@welcomehostel.pt';}
 public function newId(): string {return 'testid'.str_pad((string)++$this->counter,12,'0',STR_PAD_LEFT);}
 public function folder(string $id,string $parent,string $name): void {}
 public function metadata(string $id): array {return ['id'=>$id,'trashed'=>false,'mimeType'=>'application/vnd.google-apps.folder','owners'=>[['emailAddress'=>'daniel.ciorcas@welcomehostel.pt']]];}
 public function upload(string $id,string $parent,string $name,string $path,string $format): array {
  $this->uploads[]=$id;
  if($this->fail)throw new RuntimeException('drive_network');
  return ['id'=>$id,'parents'=>[$parent],'trashed'=>false,'size'=>filesize($path),'md5Checksum'=>$this->corrupt?'incorrect':hash_file('md5',$path)];
 }
};
$drive=new InvoiceDrive($pdo,$vault,$client);$now=time();
try {
 $drive->run($now);
 $row=$pdo->query("SELECT * FROM invoice_document_delivery WHERE document_id=$doc")->fetch(PDO::FETCH_ASSOC);
 if((int)$row['attempts']!==1 || $row['drive_state']!=='retry' || strtotime($row['next_attempt_at'].' UTC')!==$now+10800)throw new RuntimeException('Initial retry schedule');
 $drive->run($now+10799);
 if((int)$pdo->query("SELECT attempts FROM invoice_document_delivery WHERE document_id=$doc")->fetchColumn()!==1)throw new RuntimeException('Retried early');
 for($i=1;$i<=8;$i++)$drive->run($now+$i*10800);
 $row=$pdo->query("SELECT * FROM invoice_document_delivery WHERE document_id=$doc")->fetch(PDO::FETCH_ASSOC);
 if((int)$row['attempts']!==9 || $row['drive_state']!=='failed' || $row['next_attempt_at']!==null)throw new RuntimeException('Eight retry cap');
 if((int)$pdo->query('SELECT COUNT(*) FROM invoice_drive_alerts')->fetchColumn()!==1)throw new RuntimeException('One terminal alert');
 $path=$vault->path(hash('sha256',$content).'.csv');
 if(!is_file($path))throw new RuntimeException('Failed upload deleted original');
 if(count(array_unique($client->uploads))!==1)throw new RuntimeException('Upload identity changed during retries');
 $drive->retry($doc);$client->fail=false;$client->corrupt=true;$drive->run($now+9*10800);
 if(!is_file($path))throw new RuntimeException('Corrupt remote copy deleted original');
 $client->corrupt=false;$drive->run($now+10*10800);
 $row=$pdo->query("SELECT * FROM invoice_document_delivery WHERE document_id=$doc")->fetch(PDO::FETCH_ASSOC);
 if($row['drive_state']!=='verified'||is_file($path)||$row['company_state']!=='validated')throw new RuntimeException('Verified archive cleanup');
 if(count(array_unique($client->uploads))!==1)throw new RuntimeException('Manual retry created duplicate');
 echo "Drive retries, 24h cap, terminal alert, persistent identity, corrupt upload retention and verified cleanup passed.\n";
}finally{foreach(glob($tmp.'/*')?:[]as$f)unlink($f);rmdir($tmp);}
