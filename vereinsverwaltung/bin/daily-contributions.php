<?php declare(strict_types=1);
/** Run daily. Dry-run is the default; local MailPolicy always forbids dispatch. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,ContributionReminders,MailPolicy};
date_default_timezone_set('Europe/Berlin');
$options=getopt('',['actor:','database:','execute','dispatch','help']);
if(isset($options['help'])){
    echo "Daily contribution worker (Europe/Berlin)\n";
    echo "php bin/daily-contributions.php --actor=ACTOR [--database=MYSQL_DSN] [--execute] [--dispatch]\n";
    echo "Default: preview only. --execute creates due annual invoices and queues reminders.\n";
    echo "--dispatch additionally needs --execute, MailPolicy approval and VEREIN_MAIL_TRANSPORT.\n";
    echo "A configured transport PHP file must return callable(email,subject,text,html,attachments).\n";
    echo "No transport is loaded and no email is sent in the local/synthetic environment.\n";exit(0);
}
try{
    $actor=$options['actor']??getenv('VEREIN_WORKER_ACTOR')?:'';if(!is_string($actor)||$actor==='')throw new RuntimeException('Finanzberechtigten Worker-Zugang mit --actor angeben.');
    $path=$options['database']??getenv('VEREIN_DATABASE')?:'';
    if(!is_string($path)||!str_starts_with(strtolower($path),'mysql:'))throw new RuntimeException('Bestehende Datenbank erforderlich; der Worker initialisiert keine Datenbank.');
    $execute=isset($options['execute']);if(isset($options['dispatch'])&&!$execute)throw new RuntimeException('--dispatch erfordert --execute.');
    $db=Database::connect($path);$automatic=(new \Kulturhus\Verein\Finance\Contributions\ContributionMailPolicy($db,$actor))->automaticAllowed();$worker=new ContributionReminders($db,$actor);$result=$worker->run(date('Y-m-d'),$execute&&$automatic);$result['automatic_enabled']=$automatic;$result['sent']=0;
    $publicUrl=(string)getenv('VEREIN_PUBLIC_URL');$result['mail_allowed']=MailPolicy::allowed($publicUrl);
    if(isset($options['dispatch'])&&$result['mail_allowed']&&$automatic){
        $provider=(string)getenv('VEREIN_MAIL_TRANSPORT');$resolved=$provider!==''?realpath($provider):false;
        if(!$resolved||!is_file($resolved))throw new RuntimeException('Serverseitiger Versandadapter VEREIN_MAIL_TRANSPORT fehlt.');
        $transport=require $resolved;if(!is_callable($transport))throw new RuntimeException('Versandadapter muss einen Callable zurückgeben.');
        $result['sent']=$worker->dispatch($publicUrl,$transport,null,fn():bool=>(new \Kulturhus\Verein\Finance\Contributions\ContributionMailPolicy($db,$actor))->automaticAllowed());
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $error){fwrite(STDERR,'Beitragslauf abgebrochen: '.$error->getMessage()."\n");exit(1);}
