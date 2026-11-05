<?php declare(strict_types=1);
use Kulturhus\Verein\{BalanceFilingRuntime,TaxBalanceRelease};

/** Private dependency injection only: no SDK, certificate, socket or transport is opened. */
final class OwnBalanceEnvironmentRuntime implements BalanceFilingRuntime
{
    public int $revision=1;public int $validations=0;public int $transmissions=0;
    public string $validationMode='valid';public string $transportMode='success';public ?Closure $duringValidation=null;
    public ?string $barrierRoot=null;public bool $waitAfterClaim=false;private bool $barrierEntered=false;
    public function proof(array $filing,bool $sending=false):array
    {
        if($sending&&$this->barrierRoot!==null&&!$this->barrierEntered){$this->barrierEntered=true;file_put_contents($this->barrierRoot.'/ready-'.getmypid(),'ready',LOCK_EX);$deadline=microtime(true)+30;while(!is_file($this->barrierRoot.'/release')){if(microtime(true)>$deadline)throw new RuntimeException('Own sending barrier timed out.');usleep(10000);}}
        return ['kind'=>'synthetic-offline','environment'=>$filing['environment'],'release'=>TaxBalanceRelease::software((int)$filing['tax_year'],$filing['data_version']),'fixture_revision'=>$this->revision];
    }
    public function validate(array $request):array
    {
        ++$this->validations;
        if($request['xml_sha256']!==hash('sha256',$request['xml'])||!in_array($request['environment'],['test','production'],true))throw new RuntimeException('Own validator received an unbound XML.');
        if($this->duringValidation)($this->duringValidation)();
        return ['code'=>0,'environment'=>$this->validationMode==='wrong-environment'?'test':$request['environment'],'xml_sha256'=>$request['xml_sha256'],'sent'=>false,'transport_attempted'=>false,'message'=>'Synthetic validation only.'];
    }
    public function transmit(#[SensitiveParameter] array $request):array
    {
        ++$this->transmissions;
        if($request['environment']!=='production'||$request['confirm']!=='SEND-PRODUCTION'||$request['xml_sha256']!==hash('sha256',$request['xml']))throw new RuntimeException('Own transport received an unbound instruction.');
        if($this->barrierRoot!==null){file_put_contents($this->barrierRoot.'/transmit-calls',getmypid()."\n",FILE_APPEND|LOCK_EX);if($this->waitAfterClaim){file_put_contents($this->barrierRoot.'/claimed-'.getmypid(),'committed claim reached transport',LOCK_EX);$deadline=microtime(true)+60;while(true){if(microtime(true)>$deadline)throw new RuntimeException('Own killed-worker safeguard elapsed.');usleep(10000);}}}
        if($this->transportMode==='unknown')throw new RuntimeException('Synthetic crash after committed claim.');
        $sent=$this->transportMode==='success';
        return ['code'=>$sent?0:-1,'environment'=>$request['environment'],'xml_sha256'=>$request['xml_sha256'],'sent'=>$sent,'transport_attempted'=>$sent,'transfer_ticket'=>$sent?'own-synthetic-environment-ticket':'','message'=>'Synthetic offline result only.','response_xml'=>'<own-offline-result/>','server_xml'=>'<own-offline-result/>'];
    }
}
