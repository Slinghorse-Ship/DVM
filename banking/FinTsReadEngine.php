<?php declare(strict_types=1);
namespace Kulturhus\Banking;
use DomainException;
require_once __DIR__.'/PaymentRules.php';

/**
 * Adapter for the pinned phpFinTS library.
 *
 * Read operations and reviewed SEPA write operations share one encrypted
 * dialog. A write action can only be created from a server-side reviewed
 * order, is never reconstructed from browser supplied XML, and is completed
 * only after the bank's TAN/app (and, where required, payee verification)
 * step succeeds.
 */
final class FinTsReadEngine
{
 public function __construct(private array $config){if(class_exists(\Kulturhus\Native\Demo\Network::class))\Kulturhus\Native\Demo\Network::assertAllowed();$autoload=getenv('VEREIN_FINTS_AUTOLOAD')?:__DIR__.'/vendor/autoload.php';if(!is_file($autoload))throw new DomainException('Die private FinTS-Laufzeit ist noch nicht eingerichtet.');require_once $autoload;require_once __DIR__.'/CamtReader.php';require_once __DIR__.'/InstantTransfer.php';if(!class_exists(\Composer\InstalledVersions::class)||\Composer\InstalledVersions::getPrettyVersion('nemiah/php-fints')!=='4.1.0'||\Composer\InstalledVersions::getReference('nemiah/php-fints')!=='845c8cc973310c11ae2441e88e118c505d1b59f1')throw new DomainException('Geprüfte FinTS-Laufzeit installieren.');}
 private function registrationId():string{$id=trim((string)($this->config['product_id']??''));if(!preg_match('/\A[A-Za-z0-9]{25}\z/D',$id))throw new DomainException('Die FinTS-Registrierungs-ID muss vollständig 25 Zeichen lang sein.');return $id;}
 private function client(array $state):\Fhp\FinTs{
  $o=new \Fhp\Options\FinTsOptions();$bank=is_array($state['bank']??null)?$state['bank']:$this->config;$o->url=$bank['url'];$o->bankCode=$bank['bank_code'];$o->productName=$this->registrationId();$o->productVersion=$this->config['product_version'];$o->timeoutConnect=10;$o->timeoutResponse=35;
  return \Fhp\FinTs::new($o,\Fhp\Options\Credentials::create($state['user'],$state['pin']),isset($state['native'])?base64_decode($state['native'],true):null);
 }
  private function paymentXml(string $xml,string $rootName,string $namespace,string $accountIban):string
  {
   if(strlen($xml)>1048576||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new DomainException('SEPA-Zahlungsdatei ist ungültig.');
   $d=new \DOMDocument();$old=libxml_use_internal_errors(true);try{if(!$d->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)||!$d->documentElement||$d->documentElement->localName!=='Document'||$d->documentElement->namespaceURI!==$namespace)throw new DomainException('SEPA-Zahlungsdatei ist ungültig.');$xp=new \DOMXPath($d);$xp->registerNamespace('p',$namespace);$nodes=$xp->query($rootName==='CstmrCdtTrfInitn'?'//p:DbtrAcct/p:Id/p:IBAN':'//p:CdtrAcct/p:Id/p:IBAN');if(!$nodes||$nodes->length!==1||trim((string)$nodes->item(0)->textContent)!==$accountIban)throw new DomainException('SEPA-Zahlungsdatei und ausgewähltes Bankkonto passen nicht zusammen.');}finally{libxml_clear_errors();libxml_use_internal_errors($old);}return $xml;
  }
  private function account(array $s,array $input,string $sourceIban):\Fhp\Model\SEPAAccount
  {
   if(($s['phase']??'')!=='accounts')throw new DomainException('Zuerst die Bankkonten abrufen.');$uid=$input['uid']??'';$selected=null;foreach($s['accounts']??[] as $row)if(($row['uid']??'')===$uid)$selected=$row;if(!$selected||($selected['iban']??'')!==$sourceIban)throw new DomainException('Das für den Auftrag freigegebene Bankkonto auswählen.');return (new \Fhp\Model\SEPAAccount())->setIban($selected['iban'])->setBic($selected['bic'])->setBlz($selected['blz'])->setAccountNumber($selected['account_number'])->setSubAccount($selected['sub_account']);
  }
  public function advance(array $s,string $command,array $input):array{
  if(class_exists(\Kulturhus\Native\Demo\Network::class))\Kulturhus\Native\Demo\Network::assertAllowed();
  $f=$this->client($s);
   if($command==='discover'){$s['modes']=[];foreach($f->getTanModes() as $m)if($m->isProzessvariante2())$s['modes'][]=['id'=>$m->getId(),'name'=>$m->getName(),'medium'=>$m->needsTanMedium()];$s['phase']='select';}
  elseif($command==='select'){
   $mode=null;foreach($f->getTanModes() as $m)if((string)$m->getId()===($input['mode']??'')&&$m->isProzessvariante2())$mode=$m;if(!$mode)throw new DomainException('Ein von der Bank angebotenes TAN-Verfahren wählen.');
   $medium=null;if($mode->needsTanMedium()){$media=$f->getTanMedia($mode);$s['media']=array_map(fn($m)=>$m->getName(),$media);$given=$input['medium']??'';if(!in_array($given,$s['media'],true)){$s['phase']='select';$s['native']=base64_encode($f->persist());return $s;}$medium=$given;}
   $f->selectTanMode($mode,$medium);$s['task']='login';$s=$this->result($f,$f->login(),$s);
  }elseif($command==='continue'){
    if(!in_array($s['phase']??'',['tan','decoupled','poll','vop'],true)||empty($s['action']))throw new DomainException('Kein fortzusetzender Bankdialog.');
   // This value is never accepted from the browser: it comes from authenticated,
   // encrypted server storage bound to actor, device session and state version.
   $action=unserialize(base64_decode($s['action'],true),['allowed_classes'=>true]);
    if(!in_array(get_class($action),[\Fhp\Protocol\DialogInitialization::class,\Fhp\Action\GetSEPAAccounts::class,\Fhp\Action\GetStatementOfAccountXML::class,\Fhp\Action\SendSEPATransfer::class,\Fhp\Action\SendSEPARealtimeTransfer::class,InstantTransfer::class,\Fhp\Action\SendSEPADirectDebit::class],true))throw new DomainException('Unzulässiger Bankauftrag.');
    if($s['phase']==='tan'){$tan=$input['tan']??'';if(!is_string($tan)||$tan===''||strlen($tan)>100)throw new DomainException('TAN eingeben.');$f->submitTan($action,$tan);}
   elseif($s['phase']==='decoupled'){$mode=$f->getSelectedTanMode();$max=$mode->getMaxDecoupledChecks();$s['checks']=(int)($s['checks']??0)+1;if($max>0&&$s['checks']>$max)throw new DomainException('Bankfreigabe nicht bestätigt. Bitte neu anmelden.');$f->checkDecoupledSubmission($action);}
   elseif($s['phase']==='poll')$f->pollAction($action);
   elseif(($s['task']??'')!=='transfer'&&($s['task']??'')!=='direct-debit')throw new DomainException('Payee-Bestätigung gehört zu keinem Zahlungsauftrag.');
   else {PaymentRules::confirmPayee($s,$input);$f->confirmVop($action);}
   $s=$this->result($f,$action,$s);
  }elseif($command==='statement'){
   if(($s['phase']??'')!=='accounts')throw new DomainException('Zuerst Bankkonten abrufen.');$selected=null;foreach($s['accounts'] as $account)if($account['uid']===($input['uid']??''))$selected=$account;if(!$selected)throw new DomainException('Freigegebenes Bankkonto auswählen.');
   $a=(new \Fhp\Model\SEPAAccount())->setIban($selected['iban'])->setBic($selected['bic'])->setBlz($selected['blz'])->setAccountNumber($selected['account_number'])->setSubAccount($selected['sub_account']);
   $s['selected']=$selected;$s['from']=$input['from'];$s['to']=$input['to'];$s['task']='statement';$action=\Fhp\Action\GetStatementOfAccountXML::create($a,new \DateTime($s['from']),new \DateTime($s['to']));$f->execute($action);$s=$this->result($f,$action,$s);
  }elseif($command==='transfer'||$command==='direct-debit'){
   $order=(string)($input['order_id']??'');$orderVersion=filter_var($input['order_version']??null,FILTER_VALIDATE_INT);$sourceIban=strtoupper(preg_replace('/\s+/','',(string)($input['source_iban']??'')));if(!preg_match('/^[a-f0-9]{32}$/D',$order)||$orderVersion===false||$orderVersion<1||!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/D',$sourceIban))throw new DomainException('Zahlungsauftrag oder Bankkonto prüfen.');$a=$this->account($s,$input,$sourceIban);$xml=$this->paymentXml((string)($input['xml']??''),$command==='transfer'?'CstmrCdtTrfInitn':'CstmrDrctDbtInitn',$command==='transfer'?'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09':'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08',$sourceIban);$s['task']=$command;$s['payment_order']=$order;$s['payment_order_version']=$orderVersion;$s['payment_reference']='FinTS KH'.$order;$mode=PaymentRules::transferMode((string)($input['transfer_mode']??'standard'));$s['transfer_mode']=$mode;$action=$command==='transfer'?$this->transferAction($a,$xml,$mode,$f->getBpd()):\Fhp\Action\SendSEPADirectDebit::create($a,$xml);$f->execute($action);$s=$this->result($f,$action,$s);
  }else throw new DomainException('Unbekannter Bankdialogschritt.');
  $s['native']=base64_encode($f->persist());return $s;
 }
 /** Build without network I/O. No downgrade and no mode supplied by the browser. */
 private function transferAction(\Fhp\Model\SEPAAccount $account,string $xml,string $mode,\Fhp\Protocol\BPD $bpd):\Fhp\BaseAction
 {
  PaymentRules::transferMode($mode);
  $d=new \DOMDocument();$d->loadXML($xml,LIBXML_NONET);$xp=new \DOMXPath($d);
  $xp->registerNamespace('p','urn:iso:std:iso:20022:tech:xsd:pain.001.001.09');
  $instant=$xp->query('/p:Document/p:CstmrCdtTrfInitn/p:PmtInf/p:PmtTpInf/p:LclInstrm/p:Cd');
  if(($mode==='instant'&&($instant->length!==1||$instant->item(0)->textContent!=='INST'))||($mode==='standard'&&$instant->length!==0))throw new DomainException('Zahlungsart und freigegebene Bankdatei stimmen nicht überein.');
  if($mode==='standard')return \Fhp\Action\SendSEPATransfer::create($account,$xml);
  try {
   $parameters=$bpd->getLatestSupportedParameters('HIIPZS');
   // v1 has no explicit prohibition of conversion. Fail closed for this UI promise.
   if(!$parameters instanceof \Fhp\Segment\IPZ\HIIPZSv2)throw new DomainException('Diese Bankverbindung bietet keine Echtzeitüberweisung ohne automatische Umwandlung an. Keine Zahlung gesendet.');
   $action=new InstantTransfer($account,$xml);
   // Validate supported schema before FinTs::execute can send a payment request.
   $action->getNextRequest($bpd,null);
   return $action;
  }catch(\Fhp\UnsupportedException|\Fhp\Protocol\UnexpectedResponseException $e){throw new DomainException('Echtzeitüberweisung oder ihr Dateiformat wird über diese Bankverbindung nicht unterstützt. Keine Zahlung gesendet.',0,$e);}
 }
 private function result(\Fhp\FinTs $f,\Fhp\BaseAction $action,array $s):array{
   if($action->needsVopConfirmation()){$request=$action->getVopConfirmationRequest();$s['phase']='vop';$s['vop_info']=mb_substr(strip_tags($request?->getInformationForUser()??''),0,4000);$s['vop_result']=mb_substr((string)($request?->getVerificationResult()??''),0,120);$s['vop_reason']=mb_substr(strip_tags((string)($request?->getVerificationNotApplicableReason()??'')),0,1000);$s['vop_expires']=$request?->getExpiration()?->format(DATE_ATOM);$s['action']=base64_encode(serialize($action));return $s;}
   if($action->needsTan()){
   $mode=$f->getSelectedTanMode();$request=$action->getTanRequest();$decoupled=$mode->isDecoupled();$s['phase']=$decoupled?'decoupled':'tan';$s['challenge']=mb_substr(strip_tags($request->getChallenge()??''),0,4000);$s['medium']=$request->getTanMediumName()??'';
   $s['next_at']=time()+($decoupled?max(1,isset($s['checks'])?$mode->getPeriodicDecoupledCheckDelaySeconds():$mode->getFirstDecoupledCheckDelaySeconds()):0);$s['image']='';
   if($request->getChallengeHhdUc()){try{$image=new \Fhp\Model\TanRequestChallengeImage($request->getChallengeHhdUc());if(in_array($image->getMimeType(),['image/png','image/jpeg'],true)&&strlen($image->getData())<=524288)$s['image']='data:'.$image->getMimeType().';base64,'.base64_encode($image->getData());}catch(\InvalidArgumentException){$s['challenge'].=' Bitte die manuellen TAN-Angaben Ihrer Bank verwenden (Flickercode wird nicht animiert).';}}
   $s['action']=base64_encode(serialize($action));return $s;
  }
  if($action->needsPollingWait()){$s['phase']='poll';$s['next_at']=time()+max(1,$action->getPollingInfo()->getNextAttemptInSeconds()??5);$s['challenge']=strip_tags($action->getPollingInfo()->getInformationForUser()??'Bank verarbeitet den Abruf.');$s['action']=base64_encode(serialize($action));return $s;}
  $action->ensureDone();unset($s['action'],$s['checks'],$s['challenge'],$s['image'],$s['next_at']);
  if($s['task']==='login'){$s['task']='accounts';$next=\Fhp\Action\GetSEPAAccounts::create();$f->execute($next);return $this->result($f,$next,$s);}
   if($s['task']==='accounts'){$s['accounts']=[];foreach($action->getAccounts() as $a){$row=['iban'=>$a->getIban(),'bic'=>$a->getBic(),'blz'=>$a->getBlz(),'account_number'=>$a->getAccountNumber(),'sub_account'=>$a->getSubAccount(),'name'=>'FinTS-Bankkonto'];$row['uid']=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));$s['accounts'][]=$row;}$s['phase']='accounts';return $s;}
   if(in_array($s['task']??'', ['transfer','direct-debit'],true)){$s['phase']='payment_done';$s['payment_success']=mb_substr((string)($action->successMessage??'Die Bank hat den Zahlungsauftrag angenommen.'),0,4000);if($action instanceof InstantTransfer&&$action->convertedToStandard){$s['payment_success']='Achtung: Die Bank meldet eine normale statt einer Echtzeitüberweisung. Nicht erneut senden. '.$s['payment_success'];$s['payment_reference'].=' / Bank meldet Normalüberweisung';}$f->close();return $s;}
  $s['rows']=[];foreach($action->getBookedXML() as $xml){$s['rows']=[...$s['rows'],...CamtReader::rows($xml,(string)$s['selected']['iban'])];if(count($s['rows'])>10000)throw new DomainException('Zu viele Bankumsätze. Kleineren Zeitraum wählen.');}
  $s['phase']='ready';$f->close();return $s;
 }
}
