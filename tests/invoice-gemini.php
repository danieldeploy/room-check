<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceGemini.php';
function gc(bool $v,string $why): void { if (!$v) throw new RuntimeException($why); }
gc(class_exists('InvoiceGemini'),'Gemini client missing');
$dir=sys_get_temp_dir().'/gemini-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $vault=new InvoiceVault($dir);$calls=[];$reply=['status'=>200,'headers'=>[],'body'=>['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>'{"groups":[{"pages":[1]}]}']]]]],'usageMetadata'=>['totalTokenCount'=>100]]];
 $client=new InvoiceGemini($vault,static function($url,$payload) use (&$calls,&$reply) { $calls[]=[$url,$payload];return $reply; });
 $client->configure('AIza'.str_repeat('x',35));
 $local=['count'=>1,'pages'=>[['number'=>1,'qr'=>[]]]];
 $r=$client->analyse('%PDF-1.4 synthetic','pdf',$local);
 gc($r['groups'][0]['pages']===[1],'structured result parsed');
 gc(count($calls)===1 && str_contains($calls[0][0],'/gemini-3.5-flash-lite:generateContent'),'only fixed tested model');
 $client->analyse('%PDF-1.4 synthetic','pdf',$local);gc(count($calls)===1,'content cache prevents repeated API call');
 gc($client->status()['requests']===1 && $client->status()['tokens']===100,'actual attempt and token accounting');
 $budget=$vault->read(InvoiceGemini::BUDGET);$budget['next_at']=0;$vault->save(InvoiceGemini::BUDGET,$budget);
 $reply=['status'=>429,'headers'=>['retry-after'=>'45'],'body'=>[]];
 try {$client->analyse('%PDF-1.4 second','pdf',$local);throw new LogicException('429 ignored');} catch (InvoiceGeminiRetry $e) { gc($e->retryAt>=time()+44,'provider Retry-After honored'); }
 gc($client->status()['requests']===2,'failed attempt consumes quota');
 try {$client->analyse('%PDF-1.4 third','pdf',$local);throw new LogicException('cooldown ignored');} catch(InvoiceGeminiRetry){}
 gc(count($calls)===2,'persisted cooldown prevents hammering');
 $budget=$vault->read(InvoiceGemini::BUDGET);$budget['next_at']=0;$budget['requests']=400;$vault->save(InvoiceGemini::BUDGET,$budget);
 try {$client->analyse('%PDF-1.4 fourth','pdf',$local);throw new LogicException('daily budget ignored');} catch(InvoiceGeminiRetry){}
 gc(count($calls)===2,'daily safety budget reserves capacity');
 gc(!str_contains(json_encode($client->status()),'AIza'),'status cannot expose key');
 echo "Gemini cache, limits, retry and secret boundaries passed\n";
} finally {foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
