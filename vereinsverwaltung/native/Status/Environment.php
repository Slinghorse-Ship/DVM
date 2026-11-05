<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge,ManagerAccess};
use Kulturhus\Verein\{ContactArchive,EnableBanking,TaxEricAdapter};
/** Private descriptors never escape into page data. All remote probes use Http's closed surface. */
final class Environment
{
    private \Closure $http;private bool $injected;private ?\Closure $guard;
    public function __construct(private \PDO $db,private string $actor,private SecretBox $box,private string $storage,?callable $http=null,?callable $guard=null){$this->guard=$guard?\Closure::fromCallable($guard):null;$this->injected=$http!==null;$this->http=$http?\Closure::fromCallable($http):Http::request(...);}
    public function monitor(?callable $clock=null):Monitor{return new Monitor($this->db,$this->actor,$this->box,$this->describe(...),$this->probe(...),$clock,$this->guard,$this->prepareCheck(...));}
    private static function renewable(array $data):bool
    {return (!empty($data['refresh_token'])&&(!isset($data['refresh_expires_at'])||$data['refresh_expires_at']>time()))||(!empty($data['username'])&&!empty($data['password']));}
    private function prepareCheck(string $id,array $actor):void
    {
        if($id!=='stadtlandfunk'||$this->injected)return;
        $c=$this->account($id,$actor);if(!$c['configured']||!$c['enabled']||($c['data']['expires_at']??0)>time()+60||!self::renewable($c['data']))return;
        $access=$this->nativeAccess();$connection=new \Kulturhus\Native\Integration\StadtLandFunk\Connection($this->db,$this->box);$state=$connection->status($access);$connection->verify($access,$state['version']);
    }
    private function nativeClub(array $actor):string{return ManagerBridge::clubKey($actor['club_id']);}
    private function nativeAccess():ManagerAccess{$guard=function(\PDO $db,string $expected):void{if($expected!==$this->actor)throw new \DomainException('Status-Verantwortung geändert.');$this->monitor()->settings();};return new ManagerAccess($this->db,new ManagerBridge($this->db,$this->actor,'native',$guard));}
    private function account(string $id,array $actor):array {
        $club=$this->nativeClub($actor);
        $row=Db::run($this->db,'SELECT version,ciphertext FROM native_private_settings WHERE club_id=? AND purpose=?',[$club,'api-'.$id])->fetch();
        if(!$row)return ['configured'=>false,'enabled'=>true,'binding'=>'missing','data'=>[]];
        $data=$this->box->open('api:'.$club.':'.$id,$row['ciphertext']);
        $configured=match($id){'meta'=>!empty($data['page_id'])&&!empty($data['token']),'sumup'=>!empty($data['merchant_code'])&&!empty($data['token']),'stripe'=>!empty($data['account_id'])&&!empty($data['secret_key']),'paypal'=>!empty($data['client_id'])&&!empty($data['client_secret'])&&!empty($data['merchant_id']),'eventbrite'=>!empty($data['organization'])&&!empty($data['token']),'stadtlandfunk'=>!empty($data['access_token'])&&!empty($data['account_id']),default=>false};
        return ['configured'=>$configured,'enabled'=>empty($data['disconnected']),'binding'=>$this->box->fingerprint((string)$row['version'].':'.$row['ciphertext'].($id==='stadtlandfunk'?':'.(($data['expires_at']??0)>time()?'active':'expired'):'')),'data'=>$data];
    }
    private function managerKey():string {
        $app=trim((string)getenv('APP_SECRET'));if(strlen($app)>=32)return hash('sha256',$app,true);
        if(getenv('VEREIN_MODE')!=='synthetic')return \Kulturhus\Verein\Infrastructure\Runtime\ServerKey::nativeFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE'));
        // A status read must never create a synthetic archive key.
        $file=dirname(__DIR__,3).'/.local/vereinsverwaltung/contact-archive.key';
        if(is_link($file)||!is_file($file)||(fileperms($file)&0077))throw new \DomainException('Privater Mail-Schlüssel nicht verfügbar.');
        $value=trim((string)file_get_contents($file));if(strlen($value)!==64||!ctype_xdigit($value))throw new \DomainException('Privater Mail-Schlüssel ungültig.');return hash('sha256',$value,true);
    }
    private function smtp(array $actor):array {
        $stored=Db::run($this->db,'SELECT 1 FROM mail_channel_settings WHERE club_id=?',[$actor['club_id']])->fetchColumn();$invoice=Db::run($this->db,'SELECT config FROM invoice_mailboxes WHERE club_id=?',[$actor['club_id']])->fetchColumn();
        if(!$stored&&!$invoice)return [];$key=$this->managerKey();$settings=(new \Kulturhus\Verein\MailChannelSettings($this->db,$this->actor,$key))->state()['data'];$accounts=[];$seen=[];
        foreach($settings['routes']as$purpose=>$channel){if(isset($seen[$channel]))continue;$seen[$channel]=true;
            if($channel==='invoices'){$data=$invoice?json_decode($invoice,true,32,JSON_THROW_ON_ERROR):[];if(empty($data['smtp_enabled']))continue;}
            elseif(empty($settings['channels'][$channel]['override']))continue;
            $accounts[]=(new \Kulturhus\Native\Delivery\MailAccount($this->db,$this->box,static fn()=>$key))->configuration($this->nativeAccess()->club(),$purpose);
        }return $accounts;
    }
    private function imap(array $actor):array {
        $accounts=[];$data=(new \Kulturhus\Native\Communication\Mail\Settings($this->db,$this->box))->configuration($this->nativeAccess())['data'];
        if(!empty($data['imap_host']))$accounts[]=['imap_host'=>$data['imap_host'],'imap_user'=>$data['imap_user'],'imap_password'=>$data['imap_password'],'enabled'=>!empty($data['receive_enabled'])];
        $row=Db::run($this->db,'SELECT config FROM invoice_mailboxes WHERE club_id=?',[$actor['club_id']])->fetchColumn();$invoice=$row?json_decode($row,true,32,JSON_THROW_ON_ERROR):[];
        if(!empty($invoice['enabled']))$accounts[]=\Kulturhus\Verein\InvoiceMailbox::imapConfiguration($this->db,$actor['club_id'],$this->managerKey());
        foreach(array_keys(\Kulturhus\Verein\Communication\Mailbox::ACCOUNTS)as$mailbox){$configuration=(new \Kulturhus\Verein\Communication\Mailbox($this->db,$this->actor,$this->managerKey(),mailbox:$mailbox))->imapConfiguration();if($configuration)$accounts[]=$configuration;}
        return $accounts;
    }
    private function fints(array $actor):array {
        $root=EnableBanking::root();$path=$root.'/fints.json';if(!is_file($path))return ['configured'=>false,'binding'=>'missing','data'=>[],'reason'=>'bank_setup_missing'];
        if(is_link($path)||(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077)))throw new \DomainException('Private Bankkonfiguration prüfen.');
        $config=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if(($config['club_id']??'')!==$actor['club_id'])throw new \DomainException('Bankkonfiguration gehört nicht zum Verein.');
        $maps=Db::run($this->db,"SELECT id,identity_hash,account_id,created_at FROM bank_account_mappings WHERE club_id=? AND provider='fints' AND environment='PRODUCTION' ORDER BY id",[$actor['club_id']])->fetchAll();
        $files=[$path,$root.'/fints-credentials.enc',$root.'/fints-state.key'];$parts=[];$changed=0;$complete=true;foreach($files as$file){clearstatcache(true,$file);if(is_link($file)||(is_file($file)&&PHP_OS_FAMILY!=='Windows'&&(fileperms($file)&0077)))throw new \DomainException('Private Bankzugänge prüfen.');$present=is_file($file)&&filesize($file)>0;$complete=$complete&&$present;$parts[]=$present?hash_file('sha256',$file):'missing';if($present)$changed=max($changed,(int)filemtime($file));}
        return ['configured'=>$complete&&(bool)$maps,'binding'=>$this->box->fingerprint(json_encode([$parts,$maps],JSON_THROW_ON_ERROR)),'data'=>['maps'=>$maps,'changed_at'=>$changed],'reason'=>!$complete?'bank_login_missing':(!$maps?'bank_mapping_missing':'')];
    }
    public function describe(string $id,array $actor):array {
        Catalog::service($id);$kind=in_array($id,['meta','sumup','stripe','paypal','eventbrite','stadtlandfunk','smtp','imap'],true)?'provider':(in_array($id,['fints','scheduler'],true)?'passive':'local');
        if(in_array($id,['meta','sumup','stripe','paypal','eventbrite','stadtlandfunk'],true)){$c=$this->account($id,$actor);$result=array_intersect_key($c,array_flip(['configured','enabled','binding']))+['kind'=>$kind,'environment'=>in_array($c['data']['mode']??'', ['test','sandbox'],true)?'test':(($c['data']['mode']??'')==='live'?'production':null)];if($id==='stadtlandfunk'&&$c['enabled']&&$c['configured']&&($c['data']['expires_at']??0)<=time()&&!self::renewable($c['data']))$result+=['allowed'=>false,'reason'=>'reauthentication_required'];return $result;}
        if($id==='eric'){$s=TaxEricAdapter::status();$binding='missing';if($s['available']){$c=TaxEricAdapter::configuration();$binding=$this->box->fingerprint(hash_file('sha256',$c['_path']).hash_file('sha256',$c['library']).hash_file('sha256',$c['catalog']));}return ['configured'=>$s['available'],'binding'=>$binding,'kind'=>$kind,'reason'=>!$s['runtime_configured']?'eric_runtime_missing':(!$s['manufacturer_id_valid']?'eric_registration_missing':'')];}
        if($id==='fints'){$c=$this->fints($actor);return ['configured'=>$c['configured'],'binding'=>$c['binding'],'kind'=>$kind,'reason'=>$c['reason']??''];}
        if(in_array($id,['smtp','imap'],true)){
            if(empty($actor['is_superuser']))return ['configured'=>false,'binding'=>'restricted','kind'=>$kind,'allowed'=>false];
            $c=$id==='smtp'?$this->smtp($actor):$this->imap($actor);
            return ['configured'=>(bool)$c,'enabled'=>getenv('KULTURHUS_DISABLE_MAIL')!=='1'&&($id==='smtp'||(bool)array_filter($c,static fn($account)=>!empty($account['enabled']))),'binding'=>$this->box->fingerprint(json_encode($c,JSON_THROW_ON_ERROR)),'kind'=>$kind];
        }
        if($id==='push'){$file=\Kulturhus\Verein\WebPushDelivery::keyPath();return ['configured'=>is_file($file),'enabled'=>getenv('VEREIN_MODE')==='production','binding'=>is_file($file)?$this->box->fingerprint(hash_file('sha256',$file)):'missing','kind'=>'local'];}
        return ['configured'=>true,'binding'=>$this->box->fingerprint(json_encode([$actor['club_id'],$id,\Kulturhus\Native\Installer\PackageVersion::GENERATION,$id==='storage'?(realpath($this->storage)?:$this->storage):''],JSON_THROW_ON_ERROR)),'kind'=>$kind];
    }
    private static function outcome(string $state,string $reason,?int $observed=null):array{return ['state'=>$state,'reason'=>$reason]+($observed!==null?['observed_at'=>$observed]:[]);}
    private function api(string $provider,string $method,string $path,array $headers=[],?string $body=null):array {
        if(!Http::allowed($provider,$method,$path))throw new \DomainException('Unbekannter Statusabruf.');
        if(getenv('KH_NATIVE_CI')==='1'&&!$this->injected)return ['status'=>0,'body'=>[],'blocked'=>true];
        return ($this->http)($provider,$method,$path,$headers,$body);
    }
    private static function response(array $r):?array {
        $status=$r['status']??0;
        if(!empty($r['blocked']))return self::outcome('restricted','fixture_transport_disabled');
        if($status===0)return self::outcome('down','connection_failed');
        if(in_array($status,[401,403],true))return self::outcome('restricted','authorization_required');
        if($status===429)return self::outcome('restricted','provider_rate_limit');
        if($status>=400&&$status<500)return self::outcome('restricted','provider_request_rejected');
        if($status>=500&&$status<=599)return self::outcome('provider_error','provider_unavailable');
        if($status!==200||($r['json_valid']??true)!==true||!is_array($r['body']??null))return self::outcome('provider_error','provider_response_invalid');
        return null;
    }
    public function probe(string $id,array $actor):array {
        if($id==='mysql')return self::outcome((int)$this->db->query('SELECT 1')->fetchColumn()===1?'ok':'application_error','database_read');
        if($id==='application'){try{\Kulturhus\Native\Installer\SchemaRegistry::assertCurrent($this->db);return self::outcome('ok','runtime_ready');}catch(\Throwable){return self::outcome('application_error','schema_not_current');}}
        if($id==='storage')return $this->storageProbe();
        if($id==='queue'){
            $club=$this->nativeClub($actor);$overdue=(int)Db::run($this->db,"SELECT COUNT(*) FROM native_delivery_jobs WHERE club_id=? AND ((status='pending' AND due_at<?) OR (status='processing' AND started_at<?))",[$club,gmdate('Y-m-d H:i:s',time()-900),gmdate('Y-m-d H:i:s',time()-900)])->fetchColumn();
            return self::outcome($overdue?'restricted':'ok',$overdue?'queue_overdue':'queue_current');
        }
        if($id==='scheduler'){
            $r=Db::run($this->db,'SELECT status,started_at FROM native_worker_runs WHERE club_id=? ORDER BY started_at DESC,id DESC LIMIT 1',[$this->nativeClub($actor)])->fetch();
            if(!$r)return self::outcome('not_checked','worker_not_observed');$at=strtotime($r['started_at'].' UTC');if($at===false)return self::outcome('application_error','worker_time_invalid');
            if(time()-$at>Monitor::FRESH_SECONDS)return self::outcome('not_checked','worker_proof_old',$at);
            return self::outcome(in_array($r['status'],['running','completed'],true)?'ok':'restricted',$r['status']==='running'?'worker_running':($r['status']==='completed'?'worker_confirmed':'worker_needs_review'),$at);
        }
        if($id==='eric'){$r=TaxEricAdapter::probe();return self::outcome($r['runtime_verified']?'ok':'application_error',$r['runtime_verified']?'eric_runtime_verified':'eric_runtime_failed');}
        if($id==='fints')return $this->fintsProbe($actor);
        if($id==='push')return $this->pushProbe();
        if($id==='smtp')return $this->smtpProbe($actor);
        if($id==='imap')return $this->imapProbe($actor);
        $c=$this->account($id,$actor)['data'];
        if($id==='paypal'){
            $provider=($c['mode']??'sandbox')==='sandbox'?'paypal-sandbox':'paypal';$r=$this->api($provider,'POST','/v1/oauth2/token',['Authorization: Basic '.base64_encode($c['client_id'].':'.$c['client_secret']),'Content-Type: application/x-www-form-urlencoded'],'grant_type=client_credentials');
            if($error=self::response($r))return $error;$token=$r['body']['access_token']??null;if(!is_string($token)||$token==='')return self::outcome('provider_error','provider_response_invalid');
            $balance=$this->api($provider,'GET','/v1/reporting/balances?currency_code=EUR',['Authorization: Bearer '.$token]);if($error=self::response($balance))return $error['state']==='restricted'?self::outcome('restricted','paypal_merchant_unverified'):$error;
            return self::outcome(($balance['body']['account_id']??null)===$c['merchant_id']?'ok':'restricted',($balance['body']['account_id']??null)===$c['merchant_id']?'account_confirmed':'account_mismatch');
        }
        if($id==='meta'){
            foreach(array_filter([$c['page_id'],$c['instagram_id']??''])as$expected){$r=$this->api('meta','GET','/'.$c['api_version'].'/'.$expected.'?fields=id',['Authorization: Bearer '.$c['token']]);if($error=self::response($r))return $error;if(($r['body']['id']??null)!==$expected)return self::outcome('restricted','account_mismatch');}
            return self::outcome('ok','account_confirmed');
        }
        if($id==='stadtlandfunk'){
            if(($c['expires_at']??0)<=time())return self::outcome('restricted','reauthentication_required');
            $r=$this->api('stadtlandfunk','GET','/app/onboardingStatus',['Authorization: Bearer '.$c['access_token'],'appVariantIdentifier: '.\Kulturhus\Native\Integration\StadtLandFunk\Login::APP]);if($error=self::response($r))return $error;
            $matches=($r['body']['ownPerson']['id']??null)===($c['account_id']??null)&&($r['body']['homeGeoArea']['id']??null)===($c['home_area_id']??null)&&($r['body']['homeGeoAreaSelectionStatus']??'')==='VALID'&&empty($r['body']['onboardingRequired']);return self::outcome($matches?'ok':'restricted',$matches?'account_confirmed':'account_mismatch');
        }
        if($id==='sumup'){$r=$this->api('sumup','GET','/v0.1/me/merchant-profile',['Authorization: Bearer '.$c['token']]);$expected=$c['merchant_code'];$key='merchant_code';}
        elseif($id==='stripe'){$r=$this->api('stripe','GET','/v1/account',['Authorization: Bearer '.$c['secret_key'],'Stripe-Account: '.$c['account_id']]);$expected=$c['account_id'];$key='id';}
        elseif($id==='eventbrite'){
            $r=$this->api('eventbrite','GET','/v3/organizations/'.$c['organization'].'/events/?status=live&page_size=1',['Authorization: Bearer '.$c['token']]);if($error=self::response($r))return $error;
            if(!is_array($r['body']['events']??null)||!array_is_list($r['body']['events'])||!is_array($r['body']['pagination']??null))return self::outcome('provider_error','provider_response_invalid');
            foreach($r['body']['events']as$event)if(!is_array($event)||($event['organization_id']??null)!==$c['organization'])return self::outcome('restricted','account_mismatch');
            return self::outcome('ok','account_confirmed');
        }
        else throw new \DomainException('Unbekannter Dienst.');
        if($error=self::response($r))return $error;if($id==='stripe'&&($r['body']['id']??null)===$expected&&($r['body']['charges_enabled']??null)!==true)return self::outcome('restricted','payments_not_enabled');return self::outcome(($r['body'][$key]??null)===$expected?'ok':'restricted',($r['body'][$key]??null)===$expected?'account_confirmed':'account_mismatch');
    }
    private function storageProbe():array {
        if(!is_dir($this->storage)||!is_readable($this->storage)||!is_writable($this->storage))return self::outcome('application_error','storage_unavailable');
        $file=rtrim($this->storage,'/').'/.status-probe-'.bin2hex(random_bytes(12));$handle=null;
        try{$handle=@fopen($file,'x+b');if(!$handle)return self::outcome('application_error','storage_unavailable');chmod($file,0600);$bytes=random_bytes(32);if(fwrite($handle,$bytes)!==32||!fflush($handle)||!rewind($handle)||fread($handle,32)!==$bytes)return self::outcome('application_error','storage_readback_failed');$free=disk_free_space($this->storage);return self::outcome($free!==false&&$free<67108864?'restricted':'ok',$free!==false&&$free<67108864?'storage_low':'storage_readback_verified');}
        finally{if(is_resource($handle))fclose($handle);if(is_file($file))unlink($file);}
    }
    private function fintsProbe(array $actor):array {
        $c=$this->fints($actor);if(!$c['configured'])return self::outcome('not_checked','bank_not_observed');$latest=PHP_INT_MAX;
        foreach($c['data']['maps']as$map){$r=Db::run($this->db,'SELECT state,started_at FROM fints_sync_runs WHERE club_id=? AND mapping_id=? ORDER BY started_at DESC,id DESC LIMIT 1',[$actor['club_id'],$map['id']])->fetch();if(!$r)return self::outcome('not_checked','bank_not_observed');$at=(new \DateTimeImmutable($r['started_at'],new \DateTimeZone('UTC')))->getTimestamp();if($at<=$c['data']['changed_at'])return self::outcome('not_checked','bank_configuration_changed');if($r['state']!=='complete')return self::outcome('restricted',$r['state']==='needs_auth'?'bank_authentication_required':'bank_needs_review',$at);$latest=min($latest,$at);}
        return self::outcome(time()-$latest>Monitor::FRESH_SECONDS?'not_checked':'ok',time()-$latest>Monitor::FRESH_SECONDS?'bank_proof_old':'bank_read_confirmed',$latest);
    }
    private function pushProbe():array {
        $file=\Kulturhus\Verein\WebPushDelivery::keyPath();$autoload=dirname(__DIR__,2).'/push-runtime/vendor/autoload.php';if(!is_file($file)||!is_file($autoload))return self::outcome('application_error','push_runtime_missing');
        require_once $autoload;$keys=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);new \Minishlink\WebPush\WebPush(['VAPID'=>$keys]);return self::outcome('ok','push_runtime_verified');
    }
    private function smtpProbe(array $actor):array {
        if(getenv('KH_NATIVE_CI')==='1')return self::outcome('restricted','fixture_transport_disabled');foreach($this->smtp($actor)as$c){\Kulturhus\Verein\InvoiceMailboxTransport::publicHost($c['host']);
        $transport=new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($c['host'],(int)$c['port'],(int)$c['port']===465);$transport->setUsername($c['username']);$transport->setPassword($c['password']);$transport->setRequireTls(true);$transport->getStream()->setTimeout(8);
        try{$transport->start();}catch(\Throwable $e){$auth=(bool)preg_match('/\b(530|534|535)\b/',$e->getMessage());return self::outcome($auth?'restricted':'down',$auth?'smtp_authentication_required':'smtp_not_confirmed');}finally{try{$transport->stop();}catch(\Throwable){}}
        }return self::outcome('ok','smtp_authenticated');
    }
    public static function imapFailure(#[\SensitiveParameter] array $errors):array {
        foreach($errors as$message)if(is_string($message)&&preg_match('/AUTHENTICATIONFAILED|AUTHORIZATIONFAILED|invalid credentials|login failed|authentication failed|can not authenticate/i',$message))return self::outcome('restricted','imap_authentication_required');
        return self::outcome('down','imap_not_confirmed');
    }
    private function imapProbe(array $actor):array {
        if(getenv('KH_NATIVE_CI')==='1')return self::outcome('restricted','fixture_transport_disabled');if(!function_exists('imap_open'))return self::outcome('application_error','imap_runtime_missing');foreach($this->imap($actor)as$c){if(empty($c['enabled']))continue;\Kulturhus\Verein\InvoiceMailboxTransport::publicHost($c['imap_host']);
        $old=[];foreach([IMAP_OPENTIMEOUT,IMAP_READTIMEOUT]as$type){$old[$type]=imap_timeout($type);imap_timeout($type,8);}$stream=false;
        try{$stream=@imap_open('{'.$c['imap_host'].':993/imap/ssl/validate-cert}INBOX',$c['imap_user'],$c['imap_password'],OP_READONLY,1);if(!$stream)return self::imapFailure(imap_errors()?:[]);}
        finally{if($stream)imap_close($stream);imap_errors();imap_alerts();foreach($old as$type=>$timeout)imap_timeout($type,$timeout);}
        }return self::outcome('ok','imap_authenticated');
    }
}
