<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../../banking/FinTsReadEngine.php';
use Kulturhus\Banking\{FinTsReadEngine,PaymentRules};
use Kulturhus\Verein\TransferOrders;
use Fhp\Action\{SendSEPARealtimeTransfer,SendSEPATransfer};
use Fhp\Protocol\BPD;
use Fhp\Segment\IPZ\{HIIPZSv1,HIIPZSv2,ParameterSEPAInstantPaymentZahlungV2};
use Fhp\Segment\SPA\{HISPASv1,ParameterSepaKontoverbindungAnfordernV1};

$n=0;
function check(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $call,string $label):void {try{$call();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$engine=new FinTsReadEngine([]);
$build=new ReflectionMethod($engine,'transferAction');
$result=new ReflectionMethod($engine,'result');
$namespace='urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';
$snapshot=['id'=>str_repeat('a',32),'created_at'=>gmdate('c'),'execution_date'=>date('Y-m-d'),'purpose'=>'Synthetic test','total_cents'=>12550,'source'=>['name'=>'Synthetic club','iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX'],'recipient'=>['name'=>'Synthetic recipient','iban'=>'DE12500105170648489890','bic'=>'PBNKDEFFXXX']];
$normal=TransferOrders::xml($snapshot);
$instant=TransferOrders::xml($snapshot+['transfer_mode'=>'instant']);
check(!str_contains($normal,'<Cd>INST</Cd>')&&str_contains($instant,'<LclInstrm>'),'distinct schema-valid normal and instant XML');
check(str_contains($normal,'<BICFI>')&&!str_contains($normal,'<BIC>'),'pain.001.09 uses BICFI');
$noBic=$snapshot;$noBic['source']['bic']='';$noBic['recipient']['bic']='';
check(str_contains(TransferOrders::xml($noBic),'NOTPROVIDED'),'IBAN-only transfer has valid debtor agent');
$account=(new Fhp\Model\SEPAAccount())->setIban($snapshot['source']['iban'])->setBic('COBADEFFXXX')->setBlz('37040044')->setAccountNumber('532013000')->setSubAccount('00');
$bpd=new BPD();
$spa=HISPASv1::createEmpty();$spa->parameter=new ParameterSepaKontoverbindungAnfordernV1();
$spa->parameter->nationaleKontoverbindungErlaubt=false;$spa->parameter->unterstuetzteSepaDatenformate=[$namespace];
$ipz=HIIPZSv2::createEmpty();$ipz->parameter=new ParameterSEPAInstantPaymentZahlungV2();
$ipz->parameter->umwandlungNachSEPAUeberweisungZulaessigErlaubt=true;
$ipz->parameter->unterstuetzteSEPADatenformate=[$namespace.'_GBIC_5'];
$bpd->parameters=['HISPAS'=>[1=>$spa],'HIIPZS'=>[2=>$ipz]];
$bpd->tanRequired=['HKIPZ'=>true];
$action=$build->invoke($engine,$account,$instant,'instant',$bpd);
check($action instanceof SendSEPARealtimeTransfer,'native instant action selected');
$segments=$action->getNextRequest($bpd,null);
check($segments[0]->umwandlungNachSEPAUeberweisungZulaessig===false,'bank conversion disabled even when bank offers conversion');
check($action->getNeedTanForSegment()==='HKIPZ','instant action requests bank authentication');
$restored=unserialize(serialize($action));
check($restored instanceof SendSEPARealtimeTransfer&&$restored->getNextRequest($bpd,null)[0]->umwandlungNachSEPAUeberweisungZulaessig===false,'continuation preserves instant action and no-conversion');
check($build->invoke($engine,$account,$normal,'standard',$bpd) instanceof SendSEPATransfer,'normal transfer uses existing action');
denied(fn()=>$build->invoke($engine,$account,$normal,'instant',$bpd),'normal XML cannot be sent as instant');
denied(fn()=>$build->invoke($engine,$account,$instant,'standard',$bpd),'instant XML cannot be sent as normal');
denied(fn()=>$build->invoke($engine,$account,$instant,'instant',new BPD()),'bank without instant rejected without sending');
$oldBpd=clone $bpd;$oldBpd->parameters['HIIPZS']=[1=>HIIPZSv1::createEmpty()];
denied(fn()=>$build->invoke($engine,$account,$instant,'instant',$oldBpd),'old bank protocol without no-conversion rejected');
$ipz->parameter->unterstuetzteSEPADatenformate=['unsupported'];
denied(fn()=>$build->invoke($engine,$account,$instant,'instant',$bpd),'unsupported instant XML schema rejected without sending');
$ipz->parameter->unterstuetzteSEPADatenformate=null;
check($build->invoke($engine,$account,$instant,'instant',$bpd) instanceof SendSEPARealtimeTransfer,'bank general format fallback stays instant');

foreach(['CompletedFullMatch','CompletedCloseMatch','CompletedNoMatch','CompletedPartialMatch','NotApplicable','unknown',''] as $status) {
 $state=['vop_result'=>$status,'vop_expires'=>date(DATE_ATOM,time()+120)];
 $presentation=PaymentRules::payee($state);
 check($presentation['warning']===($status!=='CompletedFullMatch'),'German warning classification '.$status);
 if($presentation['warning'])denied(fn()=>PaymentRules::confirmPayee($state,['confirm_vop'=>'1']),'warning requires separate consent '.$status);
 PaymentRules::confirmPayee($state,['confirm_vop'=>'1','acknowledge_vop_warning'=>'1']);
 check(true,'explicit payee consent accepted '.$status);
}
foreach([date(DATE_ATOM,time()-1),'not-a-date'] as $expiry)denied(fn()=>PaymentRules::confirmPayee(['vop_result'=>'CompletedFullMatch','vop_expires'=>$expiry],['confirm_vop'=>'1','acknowledge_vop_warning'=>'1']),'expired or invalid payee result cannot be confirmed');
denied(fn()=>PaymentRules::confirmPayee(['vop_result'=>'CompletedFullMatch'],[]),'even full match requires bank-requested confirmation');

// Real native action receives a synthetic bank VoP request, without constructing a bank connection.
$action=new \Kulturhus\Banking\InstantTransfer($account,$instant);
class SyntheticVopRequest implements Fhp\Model\VopConfirmationRequest {
 public function getInformationForUser():?string{return '<b>Synthetic different name</b>';}
 public function getExpiration():?DateTime{return new DateTime('+2 minutes');}
 public function getVerificationResult():?string{return 'CompletedCloseMatch';}
 public function getVerificationNotApplicableReason():?string{return null;}
}
$action->setVopConfirmationRequest(new SyntheticVopRequest());
$client=(new ReflectionClass(Fhp\FinTs::class))->newInstanceWithoutConstructor();
$state=$result->invoke($engine,$client,$action,['task'=>'transfer']);
check($state['phase']==='vop'&&$state['vop_result']==='CompletedCloseMatch'&&!str_contains($state['vop_info'],'<b>'),'native bank VoP response converted to safe UI state');
check(unserialize(base64_decode($state['action'])) instanceof SendSEPARealtimeTransfer,'VoP persistence retains instant class');
$action->setVopConfirmationRequest(null);
class SyntheticPolling implements Fhp\Model\PollingInfo {
 public function getNextAttemptInSeconds():?int{return 7;}
 public function getInformationForUser():?string{return 'Synthetic processing';}
}
$action->setPollingInfo(new SyntheticPolling());
$state=$result->invoke($engine,$client,$action,['task'=>'transfer']);
check($state['phase']==='poll'&&$state['next_at']>=time()+6,'native instant waiting state obeys bank delay');
check(unserialize(base64_decode($state['action'])) instanceof SendSEPARealtimeTransfer,'poll persistence retains instant class');
class SyntheticTanRequest implements Fhp\Model\TanRequest {
 public function getProcessId():string{return 'synthetic';}
 public function getChallenge():?string{return 'Synthetic transfer approval';}
 public function getTanMediumName():?string{return 'Synthetic device';}
 public function getChallengeHhdUc():?Fhp\Syntax\Bin{return null;}
}
class SyntheticFinTsClient extends Fhp\FinTs {
 public Fhp\Model\TanMode $syntheticMode;
 public function getSelectedTanMode():?Fhp\Model\TanMode{return $this->syntheticMode;}
 public function close():void{}
}
$client=(new ReflectionClass(SyntheticFinTsClient::class))->newInstanceWithoutConstructor();
$mode=new Fhp\Segment\TAN\VerfahrensparameterZweiSchrittVerfahrenV7();
$client->syntheticMode=$mode;$action->setPollingInfo(null);$action->setTanRequest(new SyntheticTanRequest());
foreach(['mobileTAN'=>'tan','DecoupledPush'=>'decoupled'] as $method=>$expected) {
 $mode->dkTanVerfahren=$method;$mode->wartezeitVorErsterStatusabfrage=5;
 $state=$result->invoke($engine,$client,$action,['task'=>'transfer']);
 check($state['phase']===$expected&&$state['medium']==='Synthetic device','native instant authentication '.$expected);
 check(unserialize(base64_decode($state['action'])) instanceof \Kulturhus\Banking\InstantTransfer,'native instant persists during '.$expected);
}
$action->setTanRequest(null);
foreach([20=>false,3270=>true] as $code=>$converted) {
 $action=new \Kulturhus\Banking\InstantTransfer($account,$instant);
 $message=new Fhp\Protocol\Message();
 $segment=Fhp\Segment\HIRMS\HIRMSv2::createEmpty();$reply=new Fhp\Segment\HIRMS\Rueckmeldung();
 $reply->rueckmeldungscode=$code;$reply->rueckmeldungstext='Synthetic bank confirmation';
 $segment->rueckmeldung=[$reply];$message->plainSegments=[$segment];$action->processResponse($message);
 $state=$result->invoke($engine,$client,$action,['task'=>'transfer','payment_reference'=>'Synthetic']);
 check($state['phase']==='payment_done'&&$action->convertedToStandard===$converted,'accepted result is not a retry '.$code);
 check(str_contains($state['payment_success'],'Nicht erneut senden')===$converted,'only actual bank conversion produces conversion warning '.$code);
}
echo "$n instant/VoP checks passed; no network or payment\n";
