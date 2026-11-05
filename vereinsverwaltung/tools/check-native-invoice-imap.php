<?php declare(strict_types=1);
/** Opt-in integration probe. NEVER part of the normal regression runner. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||$argv[1]!=='--create-temporary-imap-fixture'){
    fwrite(STDERR,"Explicit CLI opt-in required. Read check-native-invoice-imap.md first.\n");exit(2);
}
ini_set('display_errors','0');ini_set('log_errors','0');
$report=['checks'=>[],'database'=>'isolated temporary MySQL schema','smtp_messages'=>0,'cleanup'=>'not needed','database_cleanup'=>'not needed'];$stage='prerequisites';$failed=false;$stream=null;$created=false;
$mysqlAdmin=null;$db=null;$databaseCreated=false;$databaseName='';
function nativeCheck(bool $ok,string $label):void{global $report;if(!$ok)throw new RuntimeException('Check failed');$report['checks'][]=$label;}
function nativeFlags(array $rows):array{$out=[];foreach($rows as $r)$out[(int)$r->uid]=[(int)$r->seen,(int)$r->answered,(int)$r->deleted,(int)$r->flagged,(int)$r->draft];ksort($out);return $out;}
/** Small self-contained PDF, no external assets or user-provided bytes. */
function nativePdf():string{
    $stream="BT /F1 12 Tf 30 60 Td (SYNTHETIC TEST - NO PAYMENT) Tj ET\n";
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 100] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',"<< /Length ".strlen($stream)." >>\nstream\n".$stream.'endstream'];
    $pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $i=>$o){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$o."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";foreach(array_slice($offsets,1) as $o)$pdf.=sprintf("%010d 00000 n \n",$o);return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
try{
    if(!extension_loaded('imap')||!extension_loaded('pdo_mysql'))throw new RuntimeException('Missing extension');
    require __DIR__.'/../src/bootstrap.php';
    // Dedicated local test service only. Never fall back to runtime credentials or a supplied schema.
    $stage='isolated MySQL configuration';
    $mysqlDsn=trim((string)getenv('NATIVE_IMAP_TEST_MYSQL_DSN'));
    $mysqlUser=(string)getenv('NATIVE_IMAP_TEST_MYSQL_USER');
    $mysqlPassword=getenv('NATIVE_IMAP_TEST_MYSQL_PASSWORD');
    if(!preg_match('/^mysql:host=(?:127\.0\.0\.1|localhost|mysql-test)(?:;port=([0-9]{1,5}))?;charset=utf8mb4$/D',$mysqlDsn,$mysqlMatch)
        ||(isset($mysqlMatch[1])&&((int)$mysqlMatch[1]<1||(int)$mysqlMatch[1]>65535))
        ||$mysqlUser===''||$mysqlPassword===false)throw new RuntimeException('Explicit isolated MySQL test configuration required');
    $stage='input';
    $raw=stream_get_contents(STDIN,16385);if(strlen($raw)>16384)throw new RuntimeException('Input too large');$input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);unset($raw);
    if(!is_array($input)||array_diff(array_keys($input),['host','username','password','email','allow_temporary_imap_write'])||($input['allow_temporary_imap_write']??false)!==true)throw new RuntimeException('Invalid configuration');
    foreach(['host','username','password','email'] as $key)if(!is_string($input[$key]??null)||$input[$key]===''||strlen($input[$key])>1000||str_contains($input[$key],"\0"))throw new RuntimeException('Invalid value');
    if(!filter_var($input['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid address');
    $stage='create isolated MySQL fixture';
    $mysqlAdmin=\Kulturhus\Verein\Database::connect($mysqlDsn,$mysqlUser,$mysqlPassword);
    $databaseName='kh_native_imap_'.bin2hex(random_bytes(16));
    // No IF NOT EXISTS: a collision must fail without acquiring cleanup ownership.
    $mysqlAdmin->exec('CREATE DATABASE '.$databaseName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $databaseCreated=true;$report['temporary_database']=$databaseName;$report['database_cleanup']='pending';
    $db=\Kulturhus\Verein\Database::connect($mysqlDsn.';dbname='.$databaseName,$mysqlUser,$mysqlPassword);
    unset($mysqlUser,$mysqlPassword);
    \Kulturhus\Verein\Database::migrate($db);
    $db->exec("INSERT INTO clubs VALUES('native-test','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('native-admin','native-test','members',1)");
    $stage='IMAP host validation';
    \Kulturhus\Verein\InvoiceMailboxTransport::publicHost($input['host']);
    $token=bin2hex(random_bytes(16));$folder='Kulturhus-Native-Test-'.$token;$box='{'.$input['host'].':993/imap/ssl/validate-cert/norsh}';$report['temporary_folder']=$folder;
    foreach([IMAP_OPENTIMEOUT,IMAP_READTIMEOUT,IMAP_WRITETIMEOUT] as $timeout)imap_timeout($timeout,15);
    $stage='connect';$stream=@imap_open($box.'INBOX',$input['username'],$input['password'],0,1);if(!$stream)throw new RuntimeException('Connection failed');
    $stage='create isolated folder';imap_errors();$existing=@imap_list($stream,$box,$folder);$errors=imap_errors();if($existing||$errors)throw new RuntimeException('Folder check failed');
    nativeCheck(@imap_createmailbox($stream,$box.$folder),'created new random temporary folder');$created=true;$report['cleanup']='pending';
    $pdf=nativePdf();$originals=[];$marker='kulturhus-native-'.$token;
    for($i=1;$i<=2;$i++){
        $stage='append synthetic message '.$i;$boundary='boundary-'.$token;
        $message='From: native-test@example.invalid'."\r\nTo: ".$input['email']."\r\nDate: ".date(DATE_RFC2822)."\r\nMessage-ID: <".$marker.'-'.$i."@example.invalid>\r\nSubject: SYNTHETIC NO PAYMENT ".$i."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"".$boundary."\"\r\n\r\n--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSynthetic original ".$i.". No payment.\r\n--".$boundary."\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"synthetic-".$i.".pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($pdf),76,"\r\n").'--'.$boundary."--\r\n";
        nativeCheck(@imap_append($stream,$box.$folder,$message),'IMAP APPEND synthetic message '.$i.' without SMTP');
    }
    nativeCheck(@imap_reopen($stream,$box.$folder,OP_READONLY),'opened fixture read-only');$before=imap_fetch_overview($stream,'1:*');if(!is_array($before)||count($before)!==2)throw new RuntimeException('Fixture incomplete');
    $stage='read fixture originals';foreach($before as $item){$uid=(int)$item->uid;$originals[]=hash('sha256',imap_fetchheader($stream,$uid,FT_UID).imap_body($stream,$uid,FT_UID|FT_PEEK));}sort($originals);
    // Scoped to this short-lived CLI process. No worker, SMTP adapter or live DB is loaded.
    foreach(['APP_URL'=>'https://native-test.example.invalid','VEREIN_MODE'=>'production','APP_ENV'=>'production','KULTURHUS_DISABLE_MAIL'=>'0','KULTURHUS_LOCAL_DEMO'=>'0'] as $name=>$value)putenv($name.'='.$value);
    $svc=new \Kulturhus\Verein\InvoiceMailbox($db,'native-admin',random_bytes(32));$config=array_replace(\Kulturhus\Verein\InvoiceMailbox::defaults(),['enabled'=>true,'smtp_enabled'=>false,'email'=>$input['email'],'imap_host'=>$input['host'],'imap_user'=>$input['username'],'imap_password'=>$input['password'],'folder'=>$folder,'since'=>'2000-01-01']);$svc->save($config,0);unset($config,$input);
    $stage='native import';$first=$svc->fetch();$report['first_import']=$first;nativeCheck($first['new']===1&&$first['existing']===1&&$first['failed']===0&&$first['messages']===2,'native import finds two UIDs and deduplicates one PDF');
    $stage='native second import';$second=$svc->fetch();nativeCheck($second['messages']===0&&$second['new']===0,'second search after saved UID is empty');
    $saved=$db->query('SELECT sha256 FROM invoice_mail_originals')->fetchAll(PDO::FETCH_COLUMN);sort($saved);nativeCheck($saved===$originals,'both exact original messages retained');
    nativeCheck((int)$db->query('SELECT count(*) FROM invoice_mail_original_links')->fetchColumn()===2,'both original messages linked to the single document');
    nativeCheck(hash_equals(hash('sha256',$pdf),(string)$db->query('SELECT sha256 FROM document_files')->fetchColumn()),'PDF original hash preserved');
    nativeCheck((int)$db->query('SELECT count(*) FROM finance_entries')->fetchColumn()===0&&(int)$db->query('SELECT count(*) FROM invoice_approvals')->fetchColumn()===0,'no accounting entry or approval created');
    $stage='verify flags';imap_reopen($stream,$box.$folder,OP_READONLY);$after=imap_fetch_overview($stream,'1:*');nativeCheck(is_array($after)&&nativeFlags($before)===nativeFlags($after),'message flags unchanged');
}catch(Throwable $error){$failed=true;$report['failed_stage']=$stage;$report['error_class']=get_class($error);$report['error_line']=$error->getLine();}
finally{
    if($stream){
        if($created){
            try{
                if(!@imap_reopen($stream,$box.$folder,OP_READONLY))throw new RuntimeException('Cleanup reopen failed');$status=imap_status($stream,$box.$folder,SA_MESSAGES);if(!$status||$status->messages>2)throw new RuntimeException('Unexpected folder contents');
                if($status->messages){$rows=imap_fetch_overview($stream,'1:*');if(!is_array($rows)||count($rows)!==$status->messages)throw new RuntimeException('Cleanup count mismatch');foreach($rows as $row)if(!in_array((string)($row->message_id??''),['<'.$marker.'-1@example.invalid>','<'.$marker.'-2@example.invalid>'],true))throw new RuntimeException('Unrecognized content');}
                if(!@imap_reopen($stream,$box.'INBOX',OP_READONLY)||!@imap_deletemailbox($stream,$box.$folder))throw new RuntimeException('Cleanup delete failed');$report['cleanup']='temporary folder removed';
            }catch(Throwable){$failed=true;$report['cleanup']='not completed; manually inspect named temporary folder';}
        }
        try{imap_close($stream);imap_errors();imap_alerts();}catch(Throwable){$failed=true;$report['cleanup']='connection close failed; inspect named temporary folder';}
    }
    if($databaseCreated){
        try{
            unset($svc);$db=null;
            if(!preg_match('/^kh_native_imap_[a-f0-9]{32}$/D',$databaseName))throw new RuntimeException('Invalid owned schema');
            $mysqlAdmin->exec('DROP DATABASE '.$databaseName);
            $databaseCreated=false;$report['database_cleanup']='temporary schema removed';
        }catch(Throwable){$failed=true;$report['database_cleanup']='not completed; manually inspect named temporary schema';}
    }
}
$report['result']=$failed?'failed':'passed';echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";exit($failed?1:0);
