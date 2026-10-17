<?php
declare(strict_types=1);
// Read only the newest recent Hostelworld folder message's header block.
// Never read its body or return header values, addresses, filenames or links.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0');
$dir=$argv[1]??'';
if (!preg_match('~\A/home/city/mail/[a-zA-Z0-9.-]+/[a-zA-Z0-9_.+-]+/\.HOSTELWORLD/?\z~',$dir)) exit(1);
$file=null; $at=0;
foreach (['new','cur'] as $part) {
    foreach (new DirectoryIterator(rtrim($dir,'/').'/'.$part) as $entry) {
        if ($entry->isDot() || $entry->isLink() || !$entry->isFile()) continue;
        if ($entry->getMTime()>$at && $entry->getMTime()>=time()-3600) {
            $file=$entry->getPathname(); $at=$entry->getMTime();
        }
    }
}
if (!$file) { echo json_encode(['found'=>false]); exit; }
$h=fopen($file,'rb'); $headers=''; $ended=false;
while (!feof($h) && strlen($headers)<32768) {
    $line=fgets($h,4096);
    if ($line===false) break;
    if (rtrim($line,"\r\n")==='') { $ended=true; break; }
    $headers.=$line;
}
fclose($h);
if (!$ended) exit(1);
$headers=preg_replace('/\r?\n[ \t]+/',' ',$headers);
$values=[];
foreach (['from','subject'] as $name) {
    preg_match('/^'. $name .':[ \t]*(.*)$/mi',$headers,$m);
    $values[$name]=trim($m[1]??'');
}
$fromPattern='~@(?:[a-z0-9-]+\.)*hostelworld\.com(?:[> \t]|$)~i';
$subjectPattern='~(login|sign[ _-]*in|security|verification|code|authentication)~i';
$decoded=[];
foreach ($values as $key=>$value) {
    $decoded[$key]=function_exists('iconv_mime_decode') ? (iconv_mime_decode($value,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8') ?: $value) : $value;
}
echo json_encode(['found'=>true,'at'=>$at,'raw_from_matches'=>(bool)preg_match($fromPattern,$values['from']),
    'raw_subject_matches'=>(bool)preg_match($subjectPattern,$values['subject']),
    'decoded_from_matches'=>(bool)preg_match($fromPattern,$decoded['from']),
    'decoded_subject_matches'=>(bool)preg_match($subjectPattern,$decoded['subject']),
    'decode_available'=>function_exists('iconv_mime_decode')]);
