<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptAnalysis.php';
require_once dirname(__DIR__).'/deploy/verify_receipt_split.php';
function sc(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
$dir=sys_get_temp_dir().'/receipt-classification-'.bin2hex(random_bytes(8));mkdir($dir,0700);
file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $vault=new InvoiceVault($dir);$now=1800000000;$calls=[];$response=['category'=>'taloes'];$status=200;
 $gemini=new InvoiceGemini($vault,static function($url,$payload)use(&$calls,&$response,&$status){$calls[]=$payload;return ['status'=>$status,'headers'=>['retry-after'=>'480'],'body'=>['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>json_encode($response)]]]]],'usageMetadata'=>['totalTokenCount'=>20]]];},static function()use(&$now){return $now;});
 $gemini->configure(str_repeat('x',30));
 sc(method_exists($gemini,'classify'),'Dedicated layout classifier must exist');
 $bytes='%PDF-1.4 synthetic';
 sc($gemini->classify($bytes)===['category'=>'taloes'],'Classifier returns only bounded layout category');
 sc(count($calls)===1 && $calls[0]['contents'][0]['parts'][0]['inlineData']['mimeType']==='application/pdf','Classifier sends PDF only');
 sc(base64_decode($calls[0]['contents'][0]['parts'][0]['inlineData']['data'])===$bytes,'Classifier receives supplied first page bytes');
 sc($calls[0]['generationConfig']['responseJsonSchema']['properties']['category']['enum']===['a4','taloes','uncertain'],'Structured classifier limits categories');
 sc($calls[0]['generationConfig']['maxOutputTokens']===1024 && ($calls[0]['generationConfig']['thinkingConfig']['thinkingLevel']??'')==='minimal','Classifier reserves bounded room for thinking plus JSON');
 $prompt=$calls[0]['contents'][0]['parts'][1]['text'];sc(str_contains($prompt,'A4 canvas') && str_contains($prompt,'untrusted'),'Prompt separates canvas from document and distrusts document instructions');
 $gemini->classify($bytes);sc(count($calls)===1,'Classification is cached');
 $now+=10;$response=['groups'=>[['pages'=>[1]]]];$gemini->analyse($bytes,'pdf',['count'=>1]);sc(count($calls)===2,'Full analysis has separate cache');
 $now+=10;$response=['category'=>'a4','company_nif'=>'invented'];sc($gemini->classify('%PDF-1.4 other')===['category'=>'a4'],'Classifier cannot return fiscal claims');
 foreach (['jpeg',str_repeat('x',10*1024*1024+1)] as $bad) {try{$gemini->classify($bad);sc(false,'Invalid classifier input accepted');}catch(RuntimeException $e){sc($e->getMessage()==='receipt_split_limit','Non PDF and oversized input blocked');}}
 $now+=10;$response=['category'=>'unknown'];try{$gemini->classify('%PDF-1.4 bad');sc(false,'Invalid category accepted');}catch(RuntimeException $e){sc($e->getMessage()==='gemini_response','Invalid category safely rejected');}
 $now+=10;$status=429;try{$gemini->classify('%PDF-1.4 retry');sc(false,'429 ignored');}catch(InvoiceGeminiRetry $e){sc($e->retryAt===$now+480,'Classifier shares provider retry policy');}
 $before=count($calls);try{$gemini->classify('%PDF-1.4 another');sc(false,'Cooldown ignored');}catch(InvoiceGeminiRetry){}sc(count($calls)===$before,'Classifier shares persisted pacing');
 $now+=480;$status=200;$response=['category'=>'uncertain'];
 $pdf=new InvoiceReceiptPdf($vault,['private_dir'=>$dir,'node_binary'=>getenv('TEST_NODE_BINARY')?:'/usr/bin/node','runner_script'=>dirname(__DIR__).'/invoice-runner/runner.mjs']);
 $analysis=new InvoiceReceiptAnalysis($pdf,$gemini);$source=receiptSplitProbePdf();
 sc($analysis->classifySource($source)===['category'=>'uncertain'],'Source facade preserves uncertainty');
 $submitted=base64_decode(end($calls)['contents'][0]['parts'][0]['inlineData']['data']);
 sc($submitted!==$source,'Multi page source is not sent to classifier');
 sc($pdf->split($submitted,false)['count']===1,'Only first PDF page reaches Gemini');
 sc(!glob($dir.'/receipt-page-*') && !glob($dir.'/receipt-split-*'),'Classification extraction leaves no page artifacts');
 $extracted=$pdf->extract($source,[1]);$path=$vault->path('receipt-page-'.$extracted['sha256'].'.pdf');$existing=file_get_contents($path);sc($submitted===$existing,'Classifier sees page one in original source order');
 $analysis->classifySource($source);sc(is_file($path) && file_get_contents($path)===$existing,'Identical existing page cache survives classifier cleanup');
 sc(count($calls)===$before+1,'Repeated source uses classifier cache');
 $now+=10;$status=503;try{$analysis->classifySource($pdf->firstPageBytes($source)."\n");sc(false,'Provider error ignored');}catch(InvoiceGeminiRetry $e){sc($e->getMessage()==='gemini_retry','Classifier uses existing server-error retry');}
 sc(!glob($dir.'/receipt-split-*') && file_get_contents($path)===$existing,'Retry cleans extraction while preserving existing cached page');
 $budget=$vault->read(InvoiceGemini::BUDGET);$budget['next_at']=0;$budget['requests']=400;$vault->save(InvoiceGemini::BUDGET,$budget);$before=count($calls);
 try{$gemini->classify('%PDF-1.4 daily-cap');sc(false,'Daily budget ignored');}catch(InvoiceGeminiRetry $e){sc($e->getMessage()==='gemini_daily_quota','Classifier shares daily quota');}sc(count($calls)===$before,'No provider call beyond shared daily budget');
 echo "Receipt first-page classification, cache, bounds and retries passed\n";
} finally {foreach(glob($dir.'/*')?:[] as $f)if(is_file($f))unlink($f);rmdir($dir);}
