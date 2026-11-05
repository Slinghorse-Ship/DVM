<?php declare(strict_types=1);
// Real service and templates, synthetic ProcessWire storage and a capturing mail transport.
namespace ProcessWire {
    class WirePermissionException extends \RuntimeException {}
    class Page {
        public const statusTrash=8192,statusLocked=4,statusHidden=1024;
        public array $data; public array $metadata=[];
        public function __construct(array $data=[]){$this->data=$data+['id'=>0,'template'=>(object)['name'=>'newsletter-recipient']];}
        public function __get($k){return $this->data[$k]??null;}
        public function __set($k,$v):void{$this->data[$k]=$v;}
        public function of(bool $v):void{} public function save(?string $field=null):void{}
        public function isTrash():bool{return (bool)($this->data['trash']??false);}
        public function hasStatus(int $s):bool{return (bool)($this->data['locked']??false);}
        public function meta(string $key,mixed $value=null):mixed{if(func_num_args()===2)$this->metadata[$key]=$value;return $this->metadata[$key]??null;}
    }
    class User extends Page {
        public bool $active=true,$canWrite=true;
        public function isLoggedin():bool{return $this->active;}
        public function isUnpublished():bool{return !$this->active;}
        public function isSuperuser():bool{return false;}
        public function hasPermission(string $p):bool{return $this->canWrite;}
    }
    class ProcessWire {
        public object $pages,$users,$user,$database,$config,$log;
        public function __construct(){
            $this->user=new User(['id'=>1]);
            $this->users=new class($this){
                public array $all;
                public function __construct(private ProcessWire $wire){$this->all=[1=>$wire->user];}
                public function get(int $id):User{return $this->all[$id]??new User();}
                public function setCurrentUser(User $u):void{$this->wire->user=$u;}
            };
            $this->config=(object)['paths'=>(object)['root'=>'/synthetic-only/']];
            $this->log=new class {public function save(...$args):void{}};
            $this->database=new class {
                public int $locks=0;public bool $refuse=false;
                public function prepare(string $sql):object{return new class($this,$sql){
                    public function __construct(private object $db,private string $sql){}
                    public function execute(array $args):void{if(!$this->db->refuse)$this->db->locks+=str_contains($this->sql,'GET_LOCK')?1:-1;}
                    public function fetchColumn():int{return $this->db->refuse?0:1;}
                };}
            };
            $this->pages=new class {
                public array $all=[];
                public function getFresh(int $id):Page{return $this->all[$id]??new Page();}
                public function find(string $selector):array{return array_values(array_filter($this->all,fn($p)=>$p->recipient_status==='pending'&&!$p->isTrash()));}
                public function get(string $selector):Page{
                    foreach($this->all as $p){
                        if(str_contains($selector,'recipient_status=pending')&&$p->recipient_status!=='pending')continue;
                        foreach(['doi_token_hash','unsubscribe_token_hash'] as $field)if(preg_match('/'.$field.'=([a-f0-9]{64})/',$selector,$m)&&$p->$field===$m[1])return $p;
                    }return new Page();
                }
            };
        }
    }
    function kh_header(...$args):void{} function kh_footer(...$args):void{}
    function kh_e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    function kh_root(string $s=''):string{return 'https://example.invalid/'.$s;}
}
namespace Symfony\Component\Mime {
    class Email {
        public array $data=[];
        public function __call(string $n,array $v):self{$this->data[$n]=$v;return $this;}
        public function getHtmlBody():string{return $this->data['html'][0]??'';}
    }
}
namespace Kulturhus {
    class AppSecret {public static function value(object $wire):string{return 'synthetic-no-real-data';}}
    class NewsletterCampaignService {
        public static array $tokens=[];
        public function __construct(object $wire){}
        public function storeRecipientUnsubscribeToken(int $id,string $token):void{self::$tokens[$id]=$token;}
        public function forgetRecipientUnsubscribeToken(int $id):void{unset(self::$tokens[$id]);}
    }
    class PurposeMailTransport {
        public static array $mails=[];public static bool $fail=false,$configured=true;
        public static function emailSender(...$args):\Closure{
            if(!self::$configured)throw new \RuntimeException('Synthetic configuration failure');
            return static function($mail):void{self::$mails[]=$mail;if(self::$fail)throw new \RuntimeException('Synthetic uncertain delivery');};
        }
    }
}
namespace {
    use Kulturhus\{NewsletterConfirmationReminders as Reminders,NewsletterService,NewsletterCampaignService,PurposeMailTransport as Mail,AppSecret};
    use ProcessWire\{ProcessWire,Page};
    set_error_handler(static function($l,$m,$f,$n){throw new \ErrorException($m,0,$l,$f,$n);});
    $source=getenv('KH_NEWSLETTER_SOURCE_DIR')?:__DIR__.'/../../processwire/site/src';
    $views=getenv('KH_NEWSLETTER_VIEWS')?:__DIR__.'/../views';
    $template=getenv('KH_NEWSLETTER_CONFIRM_TEMPLATE')?:__DIR__.'/../../processwire/site/templates/newsletter-confirm.php';
    foreach(['MailDeliveryPolicy','WebsiteAreaAccess','NewsletterService','NewsletterConfirmationReminders'] as $class)require $source.'/'.$class.'.php';
    foreach(['KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO','APP_ENV','VEREIN_MODE'] as $key)putenv($key);
    putenv('APP_URL=https://example.invalid');
    $n=0;
    function check(bool $yes,string $label):void{global $n;if(!$yes)throw new \RuntimeException($label);++$n;}
    function throws(callable $fn,string $label):void{$thrown=false;try{$fn();}catch(\Throwable){$thrown=true;}check($thrown,$label);}
    function setup(int $count=1):array{
        Mail::$mails=[];Mail::$fail=false;Mail::$configured=true;
        $w=new ProcessWire();
        for($i=1;$i<=$count;$i++){
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $w->pages->all[$i]=new Page(['id'=>$i,'recipient_status'=>'pending','email_hash'=>hash('sha256','synthetic'.$i),
                'email_encrypted'=>base64_encode($nonce.sodium_crypto_secretbox('person'.$i.'@example.invalid',$nonce,hash('sha256',AppSecret::value($w),true))),
                'requested_at'=>time()-86400*5,'doi_token_hash'=>hash('sha256',str_repeat((string)$i,64)),
                'unsubscribe_token_hash'=>hash('sha256','old-cancel'),'consent_version'=>'synthetic-v1','topics'=>'events',
                'first_name'=>'Mara <Test>','evidence'=>json_encode(['source'=>'website-newsletter-form','requested_at'=>'original-date','ip_hash'=>'original-proof'])]);
        }
        return [$w,new Reminders($w),new NewsletterService($w)];
    }
    function token(object $mail):string{preg_match('~bestaetigen/\?token=([a-f0-9]{64})~',$mail->data['text'][0],$m);return $m[1];}
    function confirmPage(ProcessWire $wire,string $token,bool $post=false,bool $csrf=true):string{
        global $template;
        $input=new class($token,$post){public object $get,$post;public function __construct(string $token,private bool $isPost){$this->get=(object)['token'=>$token];$this->post=(object)['token'=>''];}public function requestMethod(string $v):bool{return $v==='POST'&&$this->isPost;}};
        $session=new class($csrf){public object $CSRF;public function __construct(bool $ok){$this->CSRF=new class($ok){public function __construct(private bool $ok){}public function validate():void{if(!$this->ok)throw new \RuntimeException('CSRF');}public function getTokenName():string{return 'csrf';}public function getTokenValue():string{return 'synthetic';}};}};
        $page=new Page(['title'=>'Newsletter','url'=>'/newsletter/bestaetigen/']);
        ob_start();try{require $template;return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    [$w,$r,$s]=setup(5);$p=$w->pages->all[1];$original=$p->data;
    $w->pages->all[2]->recipient_status='confirmed';$w->pages->all[3]->recipient_status='unsubscribed';$w->pages->all[4]->locked=true;$w->pages->all[5]->evidence='broken';
    check($r->overview()['available']===1,'pending valid only');
    check(!$s->canConfirm(str_repeat('1',64)),'old link expired');
    throws(fn()=>$r->queue(false),'explicit confirmation required');
    $w->user->canWrite=false;throws(fn()=>$r->queue(true),'reader denied');$w->user->canWrite=true;
    Mail::$configured=false;throws(fn()=>$r->queue(true),'configuration required');Mail::$configured=true;
    check($p->meta(Reminders::META)===null,'failed setup does not queue');
    check($r->queue(true)['queued']===1&&!Mail::$mails,'queue does not send');
    check($r->queue(true)['queued']===0,'double click cannot duplicate');
    check($r->processQueue()['sent']===1&&count(Mail::$mails)===1,'only pending receives reminder');
    $mail=Mail::$mails[0];$new=token($mail);
    check($s->canConfirm($new)&&!$s->canConfirm(str_repeat('1',64)),'fresh 48h token replaces expired token');
    foreach(['requested_at','consent_version','topics','first_name','recipient_status','email_encrypted'] as $key)check($p->$key===$original[$key],'original '.$key.' preserved');
    $proof=json_decode($p->evidence,true);check($proof['ip_hash']==='original-proof'&&$proof['requested_at']==='original-date','original evidence preserved');
    check(!str_contains(json_encode($p->metadata),$new),'queue does not store raw token');
    foreach(['html','text'] as $kind){$body=$mail->data[$kind][0];check(str_contains($body,'anschließend auf der Website auf „Newsletter bestätigen“')&&str_contains($body,'Erst dann')&&str_contains($body,'48 Stunden'),'two steps in '.$kind);}
    check(str_contains($mail->getHtmlBody(),'Mara &lt;Test&gt;')&&!str_contains($mail->getHtmlBody(),'Mara <Test>'),'escaped name');
    check(str_contains($mail->getHtmlBody(),'<img')&&str_contains($mail->getHtmlBody(),'/impressum/')&&str_contains($mail->getHtmlBody(),'/datenschutz/'),'branded mail and footer');
    check($r->queue(true)['queued']===0&&$r->processQueue()['sent']===0&&count(Mail::$mails)===1,'no repeated automatic send');
    check($r->overview()['cooldown']===1&&$r->overview()['invalid']===2,'overview accurately distinguishes unavailable reasons');
    check(str_contains(confirmPage($w,$new),'Newsletter bestätigen</button>')&&$p->recipient_status==='pending','GET opens confirmation form without opting in');
    check(str_contains(confirmPage($w,$new,true,false),'Bestätigung nicht möglich')&&$p->recipient_status==='pending','POST without CSRF cannot confirm');
    check(str_contains(confirmPage($w,$new,true),'Anmeldung bestätigt.')&&$p->recipient_status==='confirmed','POST with CSRF confirms');
    check(!$s->canConfirm($new)&&!$s->confirm($new),'token single use');
    check($r->overview()['pending']===2,'confirmed no longer reminder candidate');
    check($w->database->locks===0,'all advisory locks released');
    [$w,$r,$s]=setup();$r->queue(true);$w->pages->all[1]->recipient_status='unsubscribed';check($r->processQueue()['sent']===0&&!Mail::$mails,'revocation before worker excludes send');
    [$w,$r,$s]=setup();$r->queue(true);$w->pages->all[1]->doi_token_hash=hash('sha256','new-registration');check($r->processQueue()['skipped']===1&&!Mail::$mails,'changed signup skipped');
    [$w,$r,$s]=setup();$r->queue(true);$w->user->canWrite=false;$w->user=new \ProcessWire\User(['id'=>2]);$worker=$w->user;
    check($r->processQueue()['needs_review']===1&&!Mail::$mails&&$w->user===$worker,'author permission rechecked and worker restored');
    [$w,$r,$s]=setup();$r->queue(true);Mail::$fail=true;check($r->processQueue()['needs_review']===1,'uncertain transport flagged');Mail::$fail=false;
    check($r->queue(true)['queued']===0&&$r->processQueue()['sent']===0&&count(Mail::$mails)===1,'uncertain mail never retried automatically');
    [$w,$r,$s]=setup();$r->queue(true);$r->processQueue();$p=$w->pages->all[1];$new=token(Mail::$mails[0]);
    $proof=json_decode($p->evidence,true);$proof['confirmation_reminder']['issued_at']=time()-172801;$p->evidence=json_encode($proof);
    check(!$s->canConfirm($new),'new link also expires at 48h');
    $state=$p->meta(Reminders::META);$state['attempted_at']=time()-172801;$p->meta(Reminders::META,$state);
    check($r->queue(true)['queued']===1&&$r->processQueue()['sent']===1,'explicit fresh reminder permitted after cooldown');
    check($s->unsubscribe(NewsletterCampaignService::$tokens[1])&&$p->recipient_status==='unsubscribed','reminder revocation link works');
    [$w,$r,$s]=setup(3);$r->queue(true);check($r->processQueue(1)['sent']===1&&$r->overview()['queued']===2,'worker limit respected');
    $w->database->refuse=true;throws(fn()=>$r->queue(true),'busy lock fails closed');$w->database->refuse=false;
    putenv('KULTURHUS_DISABLE_MAIL=1');throws(fn()=>$r->queue(true),'mail policy queue');throws(fn()=>$r->processQueue(),'mail policy worker');putenv('KULTURHUS_DISABLE_MAIL');
    // Render only the new UI fragment: no POST, API or network.
    $native=['reminders'=>['pending'=>4,'available'=>2],'mail_allowed'=>true];$nativeCanWrite=true;
    $start=static fn($op)=>print('<form method="post"><input name="operation" value="'.$op.'"><input name="csrf" value="synthetic">');
    $button=static fn($text,$enabled)=>print('<button'.($enabled?'':' disabled').'>'.$text.'</button></form>');
    ob_start();require $views.'/website-newsletter-reminders.php';$html=ob_get_clean();
    check(str_contains($html,'newsletter/remind')&&str_contains($html,'name="fields[send_confirmed]" value="1" required'),'confirmation checkbox and operation rendered');
    check(str_contains($html,'dann auf der Website „Newsletter bestätigen“'),'two steps in manager');
    $dom=new \DOMDocument();$previousXmlErrors=libxml_use_internal_errors(true);
    try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html);}finally{libxml_clear_errors();libxml_use_internal_errors($previousXmlErrors);}
    $xpath=new \DOMXPath($dom);
    check($xpath->query('//details[@id="newsletter-confirmations" and not(@open)]')->length===1,'reminder options collapsed by default');
    check($xpath->query('//details[@id="newsletter-confirmations"]/summary[text()="Optionen: Anmeldung nochmal bestätigen"]')->length===1,'clearly labelled native disclosure');
    check($xpath->query('//details[@id="newsletter-confirmations"]//form')->length===1,'mail form hidden inside optional section');
    check($xpath->query('//details[@id="newsletter-confirmations"]//details[not(@open)]//input[@name="fields[send_confirmed]" and @required]')->length===1,'opening options retains separate send confirmation');
    $nativeCanWrite=false;ob_start();require $views.'/website-newsletter-reminders.php';$readonly=ob_get_clean();check(!str_contains($readonly,'<form'),'read-only cannot send');
    $normal=NewsletterService::confirmationEmail('example@example.invalid','Test','https://example.invalid/confirm','https://example.invalid/cancel');
    check(str_contains($normal->getHtmlBody(),'anschließend auf der Website'),'ordinary signup also explains second click');
    echo "$n newsletter reminder checks passed; synthetic storage and mail capture only\n";
}
