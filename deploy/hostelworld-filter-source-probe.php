<?php
declare(strict_types=1);
// Compare generated filter locations using booleans only. Do not read emails.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0');
$dir=$argv[1]??'';
if (!preg_match('~\A/home/city/mail/([a-zA-Z0-9.-]+)/([a-zA-Z0-9_.+-]+)/\.HOSTELWORLD/?\z~',$dir,$m)) exit(1);
if (in_array($m[1],['.','..'],true) || in_array($m[2],['.','..'],true)) exit(1);
$files=['account'=>'/home/city/.filter','domain'=>'/etc/vfilters/'.$m[1],
    'mailbox'=>'/home/city/etc/'.$m[1].'/'.$m[2].'/filter'];
$facts=[];
foreach ($files as $label=>$file) {
    $readable=is_file($file) && is_readable($file) && filesize($file)<=131072;
    $source=$readable ? file_get_contents($file) : '';
    $facts[$label]=['readable'=>$readable,
        'own_rule'=>strpos($source,'Room Check Hostelworld Auth v1')!==false,
        'own_pipe'=>strpos($source,'hostelworld-city-auth-pipe.php')!==false,
        'folder'=>stripos($source,'.HOSTELWORLD')!==false,
        'has_pipe_action'=>(bool)preg_match('/^\s*(?:unseen\s+)?pipe\s+/mi',$source),
        'has_finish'=>(bool)preg_match('/^\s*finish\s*$/mi',$source)];
}
echo json_encode(['compiled_filters'=>$facts]);
