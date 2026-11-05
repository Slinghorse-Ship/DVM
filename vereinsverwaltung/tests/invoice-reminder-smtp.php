<?php declare(strict_types=1);
require __DIR__.'/invoice-reminder-decisions.php';
use Kulturhus\Verein\Finance\Invoices\Reminder\{ReminderDelivery,ReminderTransport};
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

$policy=[];foreach(['VEREIN_MODE','APP_ENV','APP_URL','KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO']as$name){$policy[$name]=getenv($name);putenv($name);}
try{
    foreach(['accept','drop']as$mode){
        $root=sys_get_temp_dir().'/own-reminder-smtp-'.bin2hex(random_bytes(8));mkdir($root,0700);
        $openssl=proc_open(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',$root.'/key.pem','-out',$root.'/certificate.pem','-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($openssl)||proc_close($openssl)!==0)throw new RuntimeException('Owned SMTP certificate unavailable.');chmod($root.'/key.pem',0600);
        $server=proc_open(['python3',__DIR__.'/support/invoice-reminder-smtp-server.py',$root,$mode],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$root.'/server.log','w']],$pipes);if(!is_resource($server))throw new RuntimeException('Owned SMTP protocol unavailable.');
        try{
            $port=(int)trim(fgets($pipes[1]));if($port<1024||$port>65535)throw new RuntimeException('Owned ephemeral SMTP port required.');$factories=0;
            $transport=new ReminderTransport($db,$mailKey,static function(array $config)use($port,$root,&$factories):EsmtpTransport{
                $factories++;if($config['host']!=='smtp.example.invalid'||(int)$config['port']!==587)throw new RuntimeException('Actual configured SMTP source required.');
                $smtp=new EsmtpTransport('127.0.0.1',$port,false);$smtp->getStream()->setStreamOptions(['ssl'=>['cafile'=>$root.'/certificate.pem','verify_peer'=>true,'verify_peer_name'=>true]]);return $smtp;
            });
            $invoice=$newInvoice('Own actual loopback SMTP '.$mode);$first=$queue($invoice,'Own approved complete TLS SMTP '.$mode);$mime=$requests->eml($first['key']);$before=ownOriginalDatabaseHash($db,['finance_entries','finance_transactions','finance_allocations','accounting_positions','documents','document_files','document_requests','document_history','native_settings_requests']);
            $actual=$worker->dispatch($first['result']['delivery_id'],'https://invoice-reminder.example.invalid',$transport->send(...));
            $wire=json_decode(file_get_contents($root.'/result.json'),true,32,JSON_THROW_ON_ERROR);
            $check($wire['tls']&&$wire['authenticated']&&$wire['sender']==='<reminders@example.invalid>'&&$wire['recipient']==='<buyer@example.invalid>'&&$wire['mime_sha256']===hash('sha256',$mime)&&$wire['bytes']===strlen($mime),'actual local STARTTLS authenticated SMTP receives exactly the approved original MIME and envelope for '.$mode);
            $check($actual['state']===($mode==='accept'?'sent':'uncertain')&&$actual['attempt']['state']===($mode==='accept'?'sent':'uncertain'),'actual generic positive DATA response or dropped SMTP acknowledgement retains the correct durable outcome for '.$mode);
            if($mode==='accept')$check($actual['attempt']['external_reference']==='smtp-accepted-sha256:'.hash('sha256',$mime),'actual positive SMTP acceptance without provider id remains a confirmed transport fact');
            $worker->dispatch($first['result']['delivery_id'],'https://invoice-reminder.example.invalid',$transport->send(...));$check($factories===1&&$requests->receipt($first['key'])===$first&&$requests->eml($first['key'])===$mime&&ownOriginalDatabaseHash($db,['finance_entries','finance_transactions','finance_allocations','accounting_positions','documents','document_files','document_requests','document_history','native_settings_requests'])===$before,'actual SMTP completion never connects again or rewrites any earlier entire money document or personal-original row for '.$mode);
        }finally{fclose($pipes[1]);proc_terminate($server);proc_close($server);foreach(glob($root.'/*')as$path)unlink($path);rmdir($root);}
    }
}finally{foreach($policy as$name=>$value)putenv($value===false?$name:$name.'='.$value);}
echo json_encode(['suite'=>'invoice-reminder-smtp','checks'=>$n,'external_actions'=>0,'real_external_smtp_connections'=>0,'owned_loopback_tls_exchanges'=>2],JSON_THROW_ON_ERROR)."\n";
