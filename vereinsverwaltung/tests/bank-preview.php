<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Banking/BankPreview.php';
use Kulturhus\Verein\BankPreview;
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function denied(callable $fn):void{try{$fn();}catch(DomainException){check(true,'denied');return;}throw new RuntimeException('Expected rejection');}
putenv('VEREIN_MODE=synthetic');
foreach(['vr','dkb'] as $bank){
 $s=BankPreview::apply(null,'treasurer',true,'start',['bank'=>$bank],100);
 $input=['id'=>$s['id'],'version'=>'1'];
 check($s['status']==='waiting'&&$s['rows']===[],'No transactions before confirmation');
 denied(fn()=>BankPreview::apply($s,'member',false,'approve',$input,101));
 denied(fn()=>BankPreview::apply($s,'other-treasurer',true,'approve',$input,101));
 denied(fn()=>BankPreview::apply($s,'treasurer',true,'start',['bank'=>$bank],101));
 denied(fn()=>BankPreview::apply($s,'treasurer',true,'approve',['id'=>'wrong','version'=>'1'],101));
 denied(fn()=>BankPreview::apply($s,'treasurer',true,'approve',['id'=>$s['id'],'version'=>'0'],101));
 denied(fn()=>BankPreview::apply($s,'treasurer',true,'approve',$input,400));
 check(BankPreview::visible($s,'other',true,101)===null,'Cross-user read');
 foreach(['reject'=>'rejected','cancel'=>'cancelled'] as $action=>$status){
  $end=BankPreview::apply($s,'treasurer',true,$action,$input,101);
  check($end['status']===$status&&$end['rows']===[],'No data after cancellation or rejection');
  denied(fn()=>BankPreview::apply($end,'treasurer',true,'approve',['id'=>$end['id'],'version'=>'2'],102));
 }
 $done=BankPreview::apply($s,'treasurer',true,'approve',$input,101);
 check(count($done['rows'])===2,'Synthetic result');
 denied(fn()=>BankPreview::apply($done,'treasurer',true,'approve',$input,102));
 check(BankPreview::visible($done,'treasurer',true,400)['rows']===[],'Expired data removed');
 check(BankPreview::apply($done,'treasurer',true,'clear',['id'=>$done['id'],'version'=>'2'],103)===null,'Clear result');
}
denied(fn()=>BankPreview::apply(null,'treasurer',true,'start',['bank'=>'paypal'],100));
denied(fn()=>BankPreview::apply(null,'treasurer',true,'start',['bank'=>'dkb','pin'=>'test'],100));
putenv('VEREIN_MODE=production');
denied(fn()=>BankPreview::apply(null,'treasurer',true,'start',['bank'=>'dkb'],100));
echo "PASS $checks bank preview checks\n";
