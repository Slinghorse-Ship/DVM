<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Balance/BalanceTaxFeedback.php';
use Kulturhus\Verein\BalanceTaxFeedback as F;
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$xml='<x xmlns:g="http://www.xbrl.de/taxonomies/de-gcd-2025-04-01" xmlns:i="http://www.w3.org/2001/XMLSchema-instance"><g:genInfo.report.id.reportElement.reportElements.SGEB/></x>';
$f=['data_version'=>'Bilanz_6_9','state'=>'sent_test','xml_data'=>$xml,'xml_sha256'=>hash('sha256',$xml)];
$a=[['state'=>'sent_test','receipt_bound'=>true]];
$r=F::describe($f,$a);check($r['technical']==='confirmed','bound technical receipt confirmed');
check($r['later']==='not_applicable_sgeb'&&!$r['pollable'],'SGEB excluded from EBilanzRueckmeldung');
check(!$r['tax_approved'],'technical receipt is not a tax assessment');
foreach(['draft','failed','validated'] as $state)check(F::describe(array_replace($f,['state'=>$state]),$a)['technical']==='not_sent',"$state is not a successful transmission");
foreach(['unknown','sending'] as $state)check(F::describe(array_replace($f,['state'=>$state]),$a)['technical']==='uncertain',"$state cannot reuse old receipt");
check(F::describe($f,[])['technical']==='uncertain','missing receipt not silently confirmed');
check(F::describe($f,[['state'=>'sent_test','receipt_bound'=>false]])['technical']==='uncertain','unbound receipt not trusted');
check(F::describe($f,[['state'=>'sent_production','receipt_bound'=>true]])['technical']==='uncertain','wrong environment receipt not trusted');
check(F::describe(array_replace($f,['xml_sha256'=>'changed']),$a)['later']==='not_determined','modified XML cannot classify later returns');
foreach(['<broken',str_replace('SGEB/>','SGEB i:nil="true"/>',$xml),str_replace('SGEB/>','SGEB i:nil="1"/>',$xml),str_replace('2025-04-01','2099-04-01',$xml),'<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>'] as $bad){
    $r=F::describe(array_replace($f,['xml_data'=>$bad,'xml_sha256'=>hash('sha256',$bad)]),$a);
    check($r['later']!=='not_applicable_sgeb'&&!$r['pollable']&&!$r['tax_approved'],'invalid/nil/foreign XML cannot infer SGEB eligibility or fiscal acceptance');
}
echo "$n feedback policy checks passed\n";
