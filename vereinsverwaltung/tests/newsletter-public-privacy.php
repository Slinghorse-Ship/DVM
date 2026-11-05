<?php declare(strict_types=1);
// Execute real signup service and public template with synthetic storage and transport only.
namespace ProcessWire {
    final class ProcessWire {
        public object $pages; public object $cache; public object $database; public object $user; public object $log; public object $config;
        public function __construct() {
            $this->pages=new class {
                public int $lookups=0; public bool $missing=false;
                public function get(string $selector): Page {
                    ++$this->lookups;
                    if($selector==='/system/newsletter-empfaenger/')return new Page(['id'=>$this->missing?0:1]);
                    preg_match('/email_hash=([a-f0-9]{64})/',$selector,$match);
                    return Page::$saved[$match[1]??'']??new Page();
                }
            };
            $this->cache=new class {
                public array $values=[];
                public function get(array $keys):array{return array_intersect_key($this->values,array_flip($keys));}
                public function save(string $key,mixed $value,int $ttl):bool{$this->values[$key]=$value;return true;}
            };
            $this->database=new class {public function prepare(string $sql):object{return new class {public function execute(array $args):void{}public function fetchColumn():int{return 1;}};}};
            $this->user=new class {public bool $admin=false;public function isSuperuser():bool{return $this->admin;}};
            $this->log=new class {public array $entries=[];public function save(string $name,string $message):void{$this->entries[]=[$name,$message];}};
            $this->config=(object)['httpUserAgent'=>'Synthetic','urls'=>(object)['root'=>'https://example.invalid/']];
        }
    }
    final class Page {
        public const statusHidden=1024;
        public static array $saved=[]; public static bool $failSave=false;
        private array $data;
        public function __construct(array $data=[]){$this->data=$data+['id'=>0];}
        public function __get(string $key):mixed{return $this->data[$key]??null;}
        public function __set(string $key,mixed $value):void{$this->data[$key]=$value;}
        public function of(bool $value):void{}public function addStatus(int $status):void{}
        public function save(?string $field=null):void{if(self::$failSave)throw new \RuntimeException('Synthetic storage failure');$this->data['id']=$this->data['id']?:count(self::$saved)+2;self::$saved[$this->data['email_hash']]=$this;}
    }
    function kh_header(...$args):void{}function kh_footer(...$args):void{}
    function kh_e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
    function kh_root(string $s):string{return 'https://example.invalid/'.$s;}
}
namespace Symfony\Component\Mime {
    final class Email {public array $data=[];public function getHtmlBody():?string{return $this->data['html'][0]??null;}public function __call(string $name,array $args):self{$this->data[$name]=$args;return $this;}}
}
namespace Kulturhus {
    final class AppSecret {public static function value(object $wire):string{return str_repeat('synthetic-only',5);}}
    final class NewsletterCampaignService {public function __construct(object $wire){}public function storeRecipientUnsubscribeToken(int $id,string $token):void{}}
    final class PurposeMailTransport {
        public static bool $adminTest=false;public static bool $broken=false;public static bool $invalidTransport=false;public static bool $failSend=false;public static int $calls=0;public static array $messages=[];
        public static function allowsAdministrativeDoiTest($wire=null):bool{return self::$adminTest;}
        public static function emailSender(string $url,string $purpose,$wire=null):\Closure{
            if(self::$broken)throw new \RuntimeException('Synthetic channel failure');
            if(self::$invalidTransport)throw new \InvalidArgumentException('Synthetic invalid transport option');
            return static function($message){++self::$calls;if(self::$failSend)throw new \RuntimeException('Synthetic uncertain send');self::$messages[]=$message;};
        }
    }
}
namespace {
use Kulturhus\{NewsletterService,PurposeMailTransport as Mail,AppSecret};
use ProcessWire\{Page,ProcessWire};
$source=getenv('KH_NEWSLETTER_SOURCE_DIR')?:__DIR__.'/../../processwire/site/src';
$template=getenv('KH_NEWSLETTER_TEMPLATE')?:__DIR__.'/../../processwire/site/templates/newsletter.php';
require __DIR__.'/../../processwire/site/src/MailDeliveryPolicy.php';require $source.'/NewsletterService.php';
foreach(['KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO','APP_ENV'] as $env)putenv($env);
putenv('SMTP_DSN=synthetic://never-connect');putenv('APP_URL=https://example.invalid');$_POST=[];
$n=0;
function privacyCheck(bool $yes,string $label):void{global $n;if(!$yes)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function renderSignup(ProcessWire $wire,string $email,string $ip='192.0.2.20',array $overrides=[]):string {
    global $template;
    $config=$wire->config;$user=$wire->user;$log=$wire->log;
    $page=new Page(['title'=>'Newsletter','summary'=>'Synthetic','url'=>'/newsletter/']);
    $input=new class($email,$overrides){public object $post;public object $get;public function __construct($email,$overrides){$this->post=(object)($overrides+['email'=>$email,'first_name'=>'Synthetic','consent'=>1,'topics'=>['events']]);$this->get=(object)['preview'=>'','mail'=>0,'key'=>''];}public function requestMethod($method):bool{return $method==='POST';}};
    $session=new class($ip,!empty($overrides['invalid_csrf'])) {
        public object $CSRF;
        public function __construct(private string $ip,bool $invalid){$this->CSRF=new class($invalid){public function __construct(private bool $invalid){}public function validate():void{if($this->invalid)throw new RuntimeException('Synthetic CSRF failure');}public function getTokenName():string{return 'token';}public function getTokenValue():string{return 'synthetic-token';}};}
        public function getIP():string{return $this->ip;}
    };
    ob_start();try{require $template;return ob_get_contents();}finally{ob_end_clean();}
}
$wire=new ProcessWire();$service=new NewsletterService($wire);
$r=$service->requestSubscription('confirmed@example.invalid','Synthetic',['events'],['ip'=>'192.0.2.1','website'=>'']);
$confirmed=reset(Page::$saved);$confirmed->recipient_status='confirmed';
privacyCheck($r['delivered']&&strlen(parse_url($r['confirm_url'],PHP_URL_QUERY))===70&&strlen((string)$confirmed->doi_token_hash)===64,'ordinary signup sends a synthetic DOI with a random hashed token');
$public=new ProcessWire();
for($i=0;$i<8;++$i)renderSignup($public,'pending@example.invalid');
$before=Mail::$calls;$lookups=$public->pages->lookups;
$existing=renderSignup($public,'confirmed@example.invalid');$unknown=renderSignup($public,'new@example.invalid');
if(in_array('--prove-before',$argv,true)){
    privacyCheck($existing!==$unknown&&str_contains($existing,'notice--success')&&str_contains($unknown,'notice--error'),'original exhausted-budget response oracle reproduced');
    echo "Baseline demonstrated; fake transport only\n";exit;
}
privacyCheck($existing===$unknown&&str_contains($existing,'notice--success'),'confirmed and unknown public responses identical after exhausted IP budget');
privacyCheck(Mail::$calls===$before&&$public->pages->lookups===$lookups,'exhausted public budget stops before recipient lookup or mail');
$fresh=new ProcessWire();$before=Mail::$calls;
$ack=renderSignup($fresh,'confirmed@example.invalid','192.0.2.21');
privacyCheck(Mail::$calls===$before,'existing confirmed recipient sends no additional mail');
privacyCheck(renderSignup($fresh,'fresh@example.invalid','192.0.2.22')===$ack&&Mail::$calls===$before+1,'new signup has same acknowledgement and still sends DOI');
privacyCheck(renderSignup($fresh,'fresh@example.invalid','192.0.2.23')===$ack,'pending signup acknowledgement identical');
$freshHash=hash_hmac('sha256','fresh@example.invalid',AppSecret::value($wire));Page::$saved[$freshHash]->recipient_status='unsubscribed';
privacyCheck(renderSignup($fresh,'fresh@example.invalid','192.0.2.24')===$ack&&Page::$saved[$freshHash]->recipient_status==='pending','unsubscribed address can request DOI again without public distinction');
privacyCheck(renderSignup($fresh,' FRESH@EXAMPLE.INVALID ','192.0.2.25')===$ack,'normalized email quota suppression has identical response');
Mail::$broken=true;
privacyCheck(renderSignup(new ProcessWire(),'configured-confirmed@example.invalid')===$ack&&renderSignup(new ProcessWire(),'confirmed@example.invalid')===$ack,'transport configuration failure reveals no subscription state');Mail::$broken=false;
Mail::$invalidTransport=true;privacyCheck(renderSignup(new ProcessWire(),'invalid-transport@example.invalid')===$ack,'transport InvalidArgumentException is not mistaken for public input feedback');Mail::$invalidTransport=false;
Page::$failSave=true;privacyCheck(renderSignup(new ProcessWire(),'storage@example.invalid')===$ack,'storage failure has neutral response');Page::$failSave=false;
Mail::$failSend=true;privacyCheck(renderSignup(new ProcessWire(),'send@example.invalid')===$ack,'uncertain send has neutral response without retries');Mail::$failSend=false;
$broken=new ProcessWire();$broken->pages->missing=true;
privacyCheck(renderSignup($broken,'missing@example.invalid')===$ack&&count($broken->log->entries)===1,'operational error still logged internally');
privacyCheck(str_contains(renderSignup(new ProcessWire(),'not-an-email'),'gültige E-Mail-Adresse'),'invalid email feedback retained');
privacyCheck(str_contains(renderSignup(new ProcessWire(),'consent@example.invalid','192.0.2.30',['consent'=>0]),'Einwilligung zustimmen'),'consent remains required');
$csrf=new ProcessWire();privacyCheck(str_contains(renderSignup($csrf,'csrf@example.invalid','192.0.2.31',['invalid_csrf'=>true]),'notice--error')&&$csrf->pages->lookups===0,'CSRF validation remains ahead of service');
$_POST['website']='bot';$before=Mail::$calls;privacyCheck(renderSignup(new ProcessWire(),'bot@example.invalid')===$ack&&Mail::$calls===$before,'honeypot acknowledgement neutral without side effects');$_POST=[];
$admin=new ProcessWire();$admin->user->admin=true;Mail::$adminTest=true;putenv('SMTP_DSN');$before=Mail::$calls;
$html=renderSignup($admin,'admin-test@example.invalid');
privacyCheck(str_contains($html,'Geschützter Testablauf')&&str_contains($html,'/newsletter/bestaetigen/?token=')&&Mail::$calls===$before,'administrator no-mail DOI test links retained');
Mail::$adminTest=false;putenv('SMTP_DSN=synthetic://never-connect');
$internal=new ProcessWire();$queue=new NewsletterService($internal);$before=Mail::$calls;Mail::$broken=true;
privacyCheck(!empty($queue->requestConfirmedApplicationSubscription('confirmed@example.invalid','Synthetic',md5('confirmed'))['already_confirmed'])&&!$internal->cache->values&&Mail::$calls===$before,'internal confirmed queue item bypasses transport and public quotas');
privacyCheck(!empty($queue->requestConfirmedApplicationSubscription('queue-config@example.invalid','Synthetic',md5('config'))['retryable'])&&Mail::$calls===$before,'internal preparation failure stays retryable');Mail::$broken=false;
for($i=0;$i<12;++$i)$queue->requestConfirmedApplicationSubscription('queue-'.$i.'@example.invalid','Synthetic',md5('queue'.$i));
privacyCheck(!array_filter(array_keys($internal->cache->values),fn($key)=>str_starts_with($key,'newsletter-signup-ip-')),'internal applications never consume public shared IP quota');
Mail::$failSend=true;$thrown=false;try{$queue->requestConfirmedApplicationSubscription('queue-uncertain@example.invalid','Synthetic',md5('uncertain'));}catch(RuntimeException){$thrown=true;}
privacyCheck($thrown,'internal uncertain send still propagates and is not marked delivered');Mail::$failSend=false;
putenv('KULTURHUS_LOCAL_DEMO=1');$before=Mail::$calls;$thrown=false;try{$service->requestSubscription('local@example.invalid','Synthetic',[],[]);}catch(RuntimeException){$thrown=true;}
privacyCheck($thrown&&Mail::$calls===$before,'local mail policy remains enforced before dependencies');
echo "$n newsletter privacy/compatibility checks passed; no network or actual mail\n";
}
