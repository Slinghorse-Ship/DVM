<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Read-only AIS client. Test and production connections have separate keys and stores. */
final class EnableBanking
{
    private array $config;
    public function __construct(private string $environment, private string $actor, private string $root, private ?\Closure $transport=null)
    {
        if(class_exists(\Kulturhus\Native\Demo\Network::class))\Kulturhus\Native\Demo\Network::assertAllowed();
        if(!in_array($environment,['PRODUCTION','SANDBOX'],true)||$actor==='')throw new DomainException('Bankumgebung prüfen.');
        $path=$root.'/enable-'.strtolower($environment).'.json';
        if(!is_file($path))throw new DomainException('Diese Bankumgebung ist noch nicht eingerichtet.');
        $this->config=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($this->config['environment']??'')!==$environment)throw new DomainException('Bankkonfiguration passt nicht zur Umgebung.');
        // SSH uses a chroot; the web PHP process sees the full hosting path.
        $this->config['private_key']=$root.'/enable-'.strtolower($environment).'.key';
    }
    public static function root():string{
        if(!ProductionRuntime::enabled())return dirname(dirname(__DIR__, 2),2).'/.local/enable-banking';
        $root=getenv('VEREIN_BANKING_ROOT')?:((getenv('VEREIN_PRIVATE_ROOT')?:throw new DomainException('Privates Banking-Verzeichnis konfigurieren.')).'/kulturhus-banking');
        if(!str_starts_with($root,'/')||preg_match('/[\x00\r\n]/',$root))throw new DomainException('Privates Banking-Verzeichnis konfigurieren.');
        return rtrim($root,'/');
    }
    private function request(string $method,string $path,?array $body=null):array
    {
        if(!(($method==='GET'&&preg_match('~^/(application|aspsps(?:\?country=DE)?|accounts/[a-zA-Z0-9-]+/(details|balances|transactions)(?:\?.*)?)$~D',$path))||($method==='POST'&&in_array($path,['/auth','/sessions'],true))))throw new DomainException('Nur lesender Bankzugriff ist verfügbar.');
        if($this->transport)return ($this->transport)($method,$path,$body);
        $encode=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');
        $now=time();$unsigned=$encode(json_encode(['typ'=>'JWT','alg'=>'RS256','kid'=>$this->config['app_id']],JSON_THROW_ON_ERROR)).'.'.$encode(json_encode(['iss'=>'enablebanking.com','aud'=>'api.enablebanking.com','iat'=>$now,'exp'=>$now+300],JSON_THROW_ON_ERROR));
        $key=openssl_pkey_get_private((string)file_get_contents($this->config['private_key']));
        if(!$key||!openssl_sign($unsigned,$signature,$key,OPENSSL_ALGO_SHA256))throw new DomainException('Bankschlüssel nicht verfügbar.');
        $ch=curl_init('https://api.enablebanking.com'.$path);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$unsigned.'.'.$encode($signature),'Content-Type: application/json','Accept: application/json'],CURLOPT_CUSTOMREQUEST=>$method]);
        if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($raw===false||$status<200||$status>=300)throw new DomainException(in_array($status,[401,403],true)&&str_starts_with($path,'/accounts/')?'Die Kontofreigabe ist abgelaufen oder wurde widerrufen. Bitte die Bank oben erneut verbinden.':($status===403?'Bankanwendung noch nicht freigeschaltet oder Kontofreigabe abgelaufen.':'Bankabruf derzeit nicht möglich (HTTP '.$status.'). Bitte erneut versuchen.'));
        $value=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($value))throw new DomainException('Unvollständige Bankantwort.');return $value;
    }
    private function key():string
    {
        // Derive a storage key from this environment's private RSA key. Never share stores across environments.
        return hash('sha256',(string)file_get_contents($this->config['private_key']).'|kulturhus-ais-storage-v1',true);
    }
    private function read(string $path):array
    {
        if(!is_file($path))return [];
        $raw=(string)file_get_contents($path);if(strlen($raw)<28)throw new DomainException('Bankverbindungsspeicher beschädigt.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$this->environment);
        if($plain===false)throw new DomainException('Bankverbindungsspeicher nicht lesbar.');return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
    }
    private function write(string $path,array $data):void
    {
        $iv=random_bytes(12);$cipher=openssl_encrypt(json_encode($data,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag,$this->environment);
        if($cipher===false)throw new DomainException('Bankverbindung konnte nicht gespeichert werden.');
        $tmp=$path.'.'.bin2hex(random_bytes(8));$f=fopen($tmp,'x');if(!$f)throw new DomainException('Bankverbindung konnte nicht gespeichert werden.');chmod($tmp,0600);
        try{if(fwrite($f,$iv.$tag.$cipher)!==strlen($iv.$tag.$cipher))throw new DomainException('Bankverbindung konnte nicht gespeichert werden.');}finally{fclose($f);}
        if(!rename($tmp,$path)){unlink($tmp);throw new DomainException('Bankverbindung konnte nicht gespeichert werden.');}
    }
    private function store():string{return $this->root.'/connections-'.strtolower($this->environment).'-'.hash('sha256',$this->actor).'.enc';}
    public function application():array{return $this->request('GET','/application');}
    public function banks():array{return $this->request('GET','/aspsps?country=DE')['aspsps']??[];}
    public function connections():array{return $this->read($this->store());}
    public static function expired(array $connection):bool{
        $until=$connection['access']['valid_until']??'';$time=is_string($until)&&$until!==''?strtotime($until):false;return $time!==false&&$time<=time();
    }
    public function environment():string{return $this->environment;}
    public function actorId():string{return $this->actor;}
    public function account(string $uid):array
    {
        if(!preg_match('/^[a-zA-Z0-9-]+$/D',$uid))throw new DomainException('Konto nicht freigegeben.');
        foreach($this->connections() as $connection)foreach($connection['accounts'] as $account)if(($account['uid']??'')===$uid){if(self::expired($connection))throw new DomainException('Die Kontofreigabe ist abgelaufen. Bitte die Bank oben erneut verbinden.');return $account;}
        throw new DomainException('Konto nicht freigegeben.');
    }
    public function transactionsPage(string $uid,string $from,string $to,string $continuation=''):array
    {
        $this->account($uid);BankImport::date($from);BankImport::date($to);
        if($from>$to||strlen($continuation)>4096)throw new DomainException('Bankabrufzeitraum prüfen.');
        $query=['date_from'=>$from,'date_to'=>$to];if($continuation!=='')$query['continuation_key']=$continuation;
        return $this->request('GET','/accounts/'.$uid.'/transactions?'.http_build_query($query));
    }
    public function start(string $bank):string
    {
        $selected=null;foreach($this->banks() as $row)if(($row['name']??'')===$bank&&($row['country']??'')==='DE'){$selected=$row;break;}
        if(!$selected)throw new DomainException('Bitte eine verfügbare Bank auswählen.');
        $state=strtolower($this->environment).'-'.bin2hex(random_bytes(32));
        $response=$this->request('POST','/auth',['access'=>['valid_until'=>gmdate('Y-m-d\TH:i:s\Z',time()+min(86400,(int)($selected['maximum_consent_validity']??86400)))],'aspsp'=>['name'=>$bank,'country'=>'DE'],'state'=>$state,'redirect_url'=>$this->config['redirect_url'],'psu_type'=>'business','language'=>'de']);
        $url=(string)($response['url']??'');$parts=parse_url($url);
        $hosts=$this->environment==='SANDBOX'?['auth.enablebanking.com','tilisy-sandbox.enablebanking.com']:['auth.enablebanking.com','tilisy.enablebanking.com'];
        if(($parts['scheme']??'')!=='https'||!in_array($parts['host']??'',$hosts,true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['port']))throw new DomainException('Ungültige Weiterleitung der Bank.');
        $this->write($this->root.'/state-'.$state.'.enc',['actor'=>$this->actor,'expires'=>time()+1800,'bank'=>$bank]);return $url;
    }
    public function finish(string $state,string $code):void
    {
        if(!preg_match('/^'.strtolower($this->environment).'-[a-f0-9]{64}$/D',$state)||$code===''||strlen($code)>4096)throw new DomainException('Bankfreigabe ungültig.');
        $path=$this->root.'/state-'.$state.'.enc';$lock=fopen($this->store().'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new DomainException('Bankverbindung belegt.');chmod($this->store().'.lock',0600);
        try{
            $pending=$this->read($path);
            if(($pending['actor']??'')!==$this->actor||($pending['expires']??0)<time())throw new DomainException('Bankfreigabe abgelaufen oder gehört zu einem anderen Konto. Bitte neu verbinden.');
            unlink($path); // Consume before exchange: a failed exchange requires a new authorization.
            $session=$this->request('POST','/sessions',['code'=>$code]);
            if(empty($session['session_id'])||!is_array($session['accounts']??null))throw new DomainException('Bankfreigabe enthält keine Kontenliste.');
            $all=$this->connections();$all[$session['session_id']]=['bank'=>$pending['bank'],'created_at'=>gmdate('c'),'accounts'=>$session['accounts'],'access'=>$session['access']??[]];$this->write($this->store(),$all);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function accountData(string $uid,string $continuation=''):array
    {
        $this->account($uid);if(strlen($continuation)>4096)throw new DomainException('Konto nicht freigegeben.');
        $query=$continuation!==''?'?'.http_build_query(['continuation_key'=>$continuation]):'';
        return ['balances'=>$this->request('GET','/accounts/'.$uid.'/balances'),'transactions'=>$this->request('GET','/accounts/'.$uid.'/transactions'.$query)];
    }
}
