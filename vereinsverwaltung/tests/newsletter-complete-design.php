<?php declare(strict_types=1);
if(getenv('VEREIN_MODE')!=='synthetic')throw new RuntimeException('Synthetic environment required');
$root='/var/www/html';require $root.'/vendor/autoload.php';require $root.'/wire/core/ProcessWire.php';
$wire=new \ProcessWire\ProcessWire(\ProcessWire\ProcessWire::buildConfig($root));
$reflection=new ReflectionClass(\Kulturhus\NewsletterCampaignService::class);$service=$reflection->newInstanceWithoutConstructor();$reflection->getProperty('wire')->setValue($service,$wire);
$saved=[];foreach(['APP_URL','APP_ENV','KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO'] as $key)$saved[$key]=getenv($key);
$tokens=[];$n=0;
function newsletterCheck(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
try{
 // Only a capture callback is used; the real transport remains forbidden by synthetic VEREIN_MODE.
 putenv('APP_URL=https://example.invalid');putenv('APP_ENV=test');putenv('KULTURHUS_DISABLE_MAIL=0');putenv('KULTURHUS_LOCAL_DEMO=0');
 $send=$reflection->getMethod('sendMessage');$mail=null;
 $capture=static function($message)use(&$mail){$mail=$message;};
 $campaign=['id'=>0,'subject'=>'Layouttest','body'=>'<h2>Vollständiger Newsletter</h2><p>Nur synthetischer Testinhalt.</p><p><a class="kh-newsletter-button" href="https://example.invalid/">Zum Kulturhus</a></p>'];
 $send->invoke($service,$capture,$campaign,null,'design@example.invalid','',true);
 $html=$mail->getHtmlBody();
 newsletterCheck(str_contains($html,'Gesamte E-Mail im Browser ansehen'),'browser link visible on unsaved test');
 newsletterCheck(str_contains($html,'Newsletter abmelden')&&str_contains($html,'/newsletter/abmelden/?test=1'),'safe test unsubscribe visible');
 newsletterCheck(!str_contains($html,'/abmelden/?token='),'test never carries a subscriber token');
 newsletterCheck(str_contains($html,'src="cid:kulturhus-logo"'),'original K embedded as an image');
 preg_match('/<a[^>]*class="kh-newsletter-button"[^>]*style="([^"]*)"/',$html,$button);
 newsletterCheck(isset($button[1])&&str_contains($button[1],'color:#fff;')&&!str_contains($button[1],'color:#176b52'),'button retains contrasting white text');
 newsletterCheck(count($mail->getAttachments())===1,'one inline logo attachment');
 $mail->from('newsletter@example.invalid');$mime=$mail->toString();
 newsletterCheck(str_contains($mime,'Content-Type: image/png')&&str_contains($mime,'Content-Disposition: inline'),'PNG MIME is inline');
 newsletterCheck(!$mail->getHeaders()->has('List-Unsubscribe'),'test has no actionable one-click header');
 preg_match('/preview=([a-f0-9]{64})/',$html,$match);$token=$match[1]??'';$tokens[]=$token;
 $preview=$service->renderTestWebView($token);
 newsletterCheck(is_string($preview)&&str_contains($preview,'Vollständiger Newsletter'),'browser preview contains actual full draft');
 newsletterCheck(str_contains($preview,'apple-touch-icon.png')&&!str_contains($preview,'cid:'),'browser uses public PNG, not email CID');
 newsletterCheck($service->renderTestWebView('wrong')===null&&$service->renderTestWebView(str_repeat('0',64))===null,'invalid and unknown previews rejected');
 $wire->cache->save('kh-newsletter-test-'.$token,['html'=>'expired','expires'=>time()-1],604800);
 newsletterCheck($service->renderTestWebView($token)===null,'expired preview rejected independently of cache');
 $campaign['id']=42;$subscriberToken=str_repeat('a',64);$send->invoke($service,$capture,$campaign,null,'design@example.invalid',$subscriberToken,false);
 newsletterCheck(str_contains($mail->getHtmlBody(),'/newsletter/?mail=42&amp;key=')&&str_contains($mail->getTextBody(),'Gesamte E-Mail im Browser ansehen'),'regular browser link preserved in HTML and text');
 newsletterCheck(str_contains($mail->getHtmlBody(),'/newsletter/abmelden/?token='.$subscriberToken)&&!str_contains($mail->getHtmlBody(),'test=1'),'regular unsubscribe is recipient-specific');
 newsletterCheck($mail->getHeaders()->has('List-Unsubscribe')&&$mail->getHeaders()->has('List-Unsubscribe-Post'),'regular one-click headers preserved');
 $confirm=\Kulturhus\NewsletterService::confirmationEmail('design@example.invalid','<Test>','https://example.invalid/confirm','https://example.invalid/cancel');
 newsletterCheck(str_contains($confirm->getHtmlBody(),'&lt;Test&gt;')&&str_contains($confirm->getHtmlBody(),'cid:kulturhus-logo'),'confirmation keeps escaping and image logo');
 echo "$n complete newsletter checks passed; no SMTP and no subscriber changes\n";
}finally{
 foreach($tokens as $token)if($token!=='')$wire->cache->delete('kh-newsletter-test-'.$token);
 foreach($saved as $key=>$value)putenv($value===false?$key:$key.'='.$value);
}
