<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceGemini.php';
function gc(bool $v,string $why): void { if (!$v) throw new RuntimeException($why); }
gc(class_exists('InvoiceGemini'),'Gemini client missing');
$dir=sys_get_temp_dir().'/gemini-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $vault=new InvoiceVault($dir);$calls=[];$reply=['status'=>200,'headers'=>[],'body'=>['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>'{"groups":[{"pages":[1]}]}']]]]],'usageMetadata'=>['totalTokenCount'=>100]]];
 $client=new InvoiceGemini($vault,static function($url,$payload) use (&$calls,&$reply) { $calls[]=[$url,$payload];return $reply; });
 $opaque='synthetic-opaque-key-'.str_repeat('x',33);
 $client->configure($opaque);
 gc($vault->read(InvoiceGemini::SETTINGS)['key']===$opaque,'accepted keys are opaque, not limited to a legacy prefix');
 gc(!str_contains(json_encode($client->status()),$opaque),'opaque key excluded from status');
 foreach (['short',str_repeat('x',30)."\r\nInjected: value",str_repeat('x',30).' space',str_repeat('x',205)] as $invalid) {
  try {$client->configure($invalid);throw new LogicException('invalid header key accepted');} catch(RuntimeException $e){gc($e->getMessage()==='gemini_key_invalid','invalid key rejected safely');}
 }
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
 // Daily quota and its one next-day probe survive a calendar boundary/restart.
 $now=strtotime('2026-11-14T22:00:00Z');$dailyCalls=0;
 $vault->save(InvoiceGemini::BUDGET,[]);
 $dailyReply=['status'=>429,'body'=>['error'=>['details'=>[['violations'=>[['quotaId'=>'GenerateRequestsPerDay']]]]]]];
 $daily=new InvoiceGemini($vault,static function()use(&$dailyCalls,&$dailyReply){$dailyCalls++;return $dailyReply;},static function()use(&$now){return $now;});
 try {$daily->analyse('%PDF-1.4 daily','pdf',$local);gc(false,'daily quota must wait');} catch(InvoiceGeminiRetry $e){gc($e->getMessage()==='gemini_daily_quota' && $e->retryAt===$now+86400,'daily quota has one exact 24-hour wait');}
 $now+=86399;
 try {$daily->analyse('%PDF-1.4 daily','pdf',$local);} catch(InvoiceGeminiRetry $e){gc($e->getMessage()==='gemini_daily_quota','daily wait persists across budget day');}
 gc($dailyCalls===1,'no daily probe before 24 hours');$now++;
 try {$daily->analyse('%PDF-1.4 daily','pdf',$local);gc(false,'second daily failure must stop');} catch(RuntimeException $e){gc($e->getMessage()==='gemini_quota_probe_failed','failed daily probe requires intervention');}
 $now+=86400;try{$daily->analyse('%PDF-1.4 daily','pdf',$local);}catch(RuntimeException $e){gc($e->getMessage()==='gemini_quota_probe_failed','no endless daily retry');}
 gc($dailyCalls===2,'only one automatic next-day probe');
 InvoiceGemini::resetDailyQuota($vault);$now+=10;
 try{$daily->analyse('%PDF-1.4 recover','pdf',$local);}catch(InvoiceGeminiRetry){}
 $now+=86400;$dailyReply=['status'=>200,'body'=>['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>'{"groups":[{"pages":[1]}]}']]]]]]];
 $daily->analyse('%PDF-1.4 recover','pdf',$local);
 gc(($daily->status()['daily_quota']['state']??'')==='recovered','successful real provider result records quota recovery');
 $b=$daily->status();unset($b['configured'],$b['model']);$b['requests']=400;$b['daily_quota']=['state'=>'waiting','started_at'=>$now-86400,'retry_at'=>$now];$b['next_at']=0;$vault->save(InvoiceGemini::BUDGET,$b);$before=$dailyCalls;
 try{$daily->analyse('%PDF-1.4 local-cap-probe','pdf',$local);gc(false,'local cap must stop probe');}catch(RuntimeException $e){gc($e->getMessage()==='gemini_quota_probe_failed','local daily cap stops one probe');}
 gc($dailyCalls===$before && $daily->status()['daily_quota']['state']==='failed','local cap cannot bypass or repeat the next-day probe');
 echo "Gemini cache, limits, retry and secret boundaries passed\n";
} finally {foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
