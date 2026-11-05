<?php declare(strict_types=1);
// Real formatter and mail composition; in-memory doubles only, no transport/database.
namespace ProcessWire {
    class Page {public function __construct(public string $first_name='Mara'){} }
    class ProcessWire {
        public object $config;public object $cache;public object $database;
        public function __construct(array $campaign){
            $this->config=(object)['userAuthSalt'=>'synthetic-newsletter-secret-not-production'];
            $this->cache=new class {public array $data=[];public function save(string $key,mixed $value,int $ttl):bool{$this->data[$key]=$value;return true;}public function get(string $key):mixed{return $this->data[$key]??null;}};
            $this->database=new class($campaign) {
                public function __construct(private array $campaign){}
                public function prepare(string $sql):object{
                    if(!str_starts_with($sql,'SELECT * FROM `kulturhus_newsletter_campaigns`'))throw new \RuntimeException('Database writes forbidden in fixture');
                    return new class($this->campaign){public function __construct(private array $campaign){}public function execute(array $args):void{}public function fetch(int $mode):array{return $this->campaign;}};
                }
            };
        }
    }
}
namespace Symfony\Component\Mime {
    class Email {
        public array $data=[];private ?object $headers=null;
        public function __call(string $name,array $args):self{$this->data[$name]=$args;return $this;}
        public function getHtmlBody():string{return $this->data['html'][0]??'';}
        public function getTextBody():string{return $this->data['text'][0]??'';}
        public function getHeaders():object{return $this->headers??=new class {public array $values=[];public function addTextHeader(string $key,string $value):void{$this->values[$key]=$value;}public function has(string $key):bool{return isset($this->values[$key]);}};}
    }
}
namespace {
require __DIR__.'/../../processwire/site/src/MailDeliveryPolicy.php';
require __DIR__.'/../../processwire/site/src/NewsletterService.php';
require __DIR__.'/../../processwire/site/src/NewsletterCampaignService.php';
require __DIR__.'/../src/Web/View.php';
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
$count=0;function check(bool $ok,string $why):void{global $count;if(!$ok)throw new RuntimeException($why);++$count;}
$saved=[];foreach(['APP_URL','APP_ENV','KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO'] as $key)$saved[$key]=getenv($key);
try {
    putenv('APP_URL=https://example.invalid');putenv('APP_ENV=synthetic');putenv('KULTURHUS_DISABLE_MAIL=0');putenv('KULTURHUS_LOCAL_DEMO=0');
    $campaign=['id'=>42,'subject'=>'Synthetischer Newsletter','body'=>'<p>Liebe Kulturfreundinnen und Kulturfreunde,</p><p>Dies ist nur ein Test.</p>'];
    $wire=new \ProcessWire\ProcessWire($campaign);
    $ref=new ReflectionClass(\Kulturhus\NewsletterCampaignService::class);$service=$ref->newInstanceWithoutConstructor();$ref->getProperty('wire')->setValue($service,$wire);
    $send=$ref->getMethod('sendMessage');$mail=null;$capture=static function($value)use(&$mail){$mail=$value;};
    foreach([false,true] as $testMode)foreach([null,new \ProcessWire\Page('Mara')] as $recipient){
        $send->invoke($service,$capture,$campaign,$recipient,'test@example.invalid',str_repeat('a',64),$testMode);
        foreach([$mail->getHtmlBody(),$mail->getTextBody()] as $content){check(!str_contains($content,'Hallo'),'no automatic greeting');check(substr_count($content,'Liebe Kulturfreundinnen und Kulturfreunde,')===1,'authored salutation exactly once');}
        check(str_contains($mail->getHtmlBody(),'Gesamte E-Mail im Browser ansehen'),'browser link retained');
        check(str_contains($mail->getHtmlBody(),'Newsletter abmelden'),'unsubscribe retained');
        check($mail->getHeaders()->has('List-Unsubscribe')===!$testMode,'one-click policy unchanged');
        if($testMode){preg_match('/preview=([a-f0-9]{64})/',$mail->getHtmlBody(),$m);$preview=$service->renderTestWebView($m[1]);check(is_string($preview)&&!str_contains($preview,'Hallo')&&str_contains($preview,'Liebe Kulturfreundinnen'),'test browser view without automatic greeting');}
    }
    $token=hash_hmac('sha256','newsletter-view:42',$wire->config->userAuthSalt);
    $web=$service->renderCampaignWebView(42,$token);check(is_string($web)&&!str_contains($web,'Hallo')&&str_contains($web,'Liebe Kulturfreundinnen'),'regular browser view without automatic greeting');
    $custom=$campaign;$custom['body']='<p>Hallo zusammen,</p><p>Eine ausdrücklich gewählte Anrede bleibt erhalten.</p>';
    $send->invoke($service,$capture,$custom,null,'test@example.invalid',str_repeat('b',64),false);
    check(substr_count($mail->getHtmlBody(),'Hallo zusammen,')===1&&substr_count($mail->getTextBody(),'Hallo zusammen,')===1,'authored Hallo is not stripped');
    $_POST=[];$item=$campaign;$native=['events'=>[],'default_body'=>''];ob_start();require __DIR__.'/../views/Website/website-newsletter-builder.php';$view=ob_get_clean();
    check(!str_contains($view,'kh-builder-greeting')&&!str_contains($view,'Hallo,'),'manager editor has no fixed salutation');
    check(str_contains($view,'Liebe Kulturfreundinnen'),'editor retains authored body');
    check(!str_contains($view,'Anrede, Browseransicht'),'helper no longer promises automatic salutation');
    $legacy=file_get_contents(__DIR__.'/../../processwire/site/modules/ProcessKulturhusDashboard/ProcessKulturhusDashboard.module.php');
    check(!str_contains($legacy,'kh-builder-greeting'),'legacy editor matches mail rendering');
    $confirmation=\Kulturhus\NewsletterService::confirmationEmail('test@example.invalid','Mara','https://example.invalid/confirm','https://example.invalid/cancel');
    check(str_contains($confirmation->getHtmlBody(),'Mara'),'DOI confirmation personalization remains');
    echo "$count newsletter salutation checks passed; captured in memory, no real mail or database\n";
} finally {foreach($saved as $key=>$value)putenv($value===false?$key:$key.'='.$value);}
}
