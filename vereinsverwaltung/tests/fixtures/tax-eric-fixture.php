<?php declare(strict_types=1);
// Offline subprocess fixture. No network calls and no native SDK.
$config=json_decode((string)file_get_contents($argv[count($argv)-1]),true,64,JSON_THROW_ON_ERROR);
if(($config['test_fixture_only']??false)!==true||!str_contains($config['scratch'],'kh-eric-fixture-'))exit(99);
$rawRequest=(string)stream_get_contents(STDIN);$r=json_decode($rawRequest,true,64,JSON_THROW_ON_ERROR);
if(($r['action']??'')==='balance-generate'){
    // Exercise the actual generator, but never open the native SDK or a network.
    $p=proc_open([$config['test_python']??'/usr/bin/python3','-B',$argv[1],$argv[count($argv)-1]],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))exit(99);
    // Preserve the exact request, including empty JSON objects and value hashes.
    fwrite($pipes[0],$rawRequest);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);
    echo $out;if($err!=='')fwrite(STDERR,$err);exit($code);
}
if(in_array($r['action']??'',['feedback-fetch','feedback-ack'],true)){
    $mode=$config['feedback_mode']??'normal';
    file_put_contents($config['scratch'].'/feedback-calls.txt',$r['action']."\n",FILE_APPEND);
    $base=['code'=>0,'environment'=>$r['environment'],'transfer_ticket'=>$r['transfer_ticket']];
    if($r['action']==='feedback-ack'){
        if($mode==='ack-fail'){echo json_encode($base+['acknowledged'=>[]]);exit;}
        echo json_encode($base+['acknowledged'=>$r['provisions']]);exit;
    }
    if($mode==='empty'){echo json_encode($base+['cursor'=>'','items'=>[]]);exit;}
    $ticket=$mode==='foreign-ticket'?'foreign-synthetic-ticket':$r['transfer_ticket'];
    $xml='<TransferTicket>'.$ticket.'</TransferTicket><NutzdatenTicket>'.$r['filing_id'].'</NutzdatenTicket><Datenart>Belegnachreichung</Datenart><Anrede>Test</Anrede><Referenz>Test</Referenz><Anhaenge><Anhang><Dateibezeichnung>Anlage-1.pdf</Dateibezeichnung><Rueckmeldung>PDF beschädigt.</Rueckmeldung></Anhang></Anhaenge><Mittelteil>Test-Hinweis.</Mittelteil><Anweisung>Bitte prüfen.</Anweisung><Grussformel>ELSTER</Grussformel>';
    echo json_encode($base+['cursor'=>'','items'=>[['id'=>'synthetic-provision-0001','xml'=>$xml,'sha256'=>hash('sha256',$xml),'feedback'=>['attachments'=>[['name'=>'Anlage-1.pdf','message'=>'PDF beschädigt.']],'message'=>'Test-Hinweis.','instruction'=>'Bitte prüfen.']]]]);exit;
}
if(($r['action']??'')==='certificate'){
    $bytes=base64_decode($r['certificate_b64']??'');
    if(($r['pin']??'')!=='fixture secret' || !in_array($bytes,['synthetic-org','synthetic-org-new','synthetic-person','synthetic-test','synthetic-expired'],true)){
        echo json_encode(['code'=>-1,'sent'=>false,'transport_attempted'=>false,'message'=>'Synthetic certificate rejected']);exit(2);
    }
    echo json_encode(['code'=>0,'sent'=>false,'transport_attempted'=>false,'certificate'=>['organization'=>$bytes!=='synthetic-person','test'=>$bytes==='synthetic-test','valid_until'=>$bytes==='synthetic-expired'?'2001-01-01T00:00:00Z':'2099-01-01T00:00:00Z']]);exit;
}
if(($r['action']??'')==='send'&&base64_decode($r['certificate_b64']??'')==='crash-after-claim')exit(9);
if(($r['action']??'')==='send'&&($r['data_version']??'')==='Belegnachreichung_24'&&base64_decode($r['certificate_b64']??'')==='fail-evidence')exit(9);
if(($r['action']??'')==='send'&&base64_decode($r['certificate_b64']??'')==='wrong-pin'){echo json_encode(['code'=>-1,'message'=>'Synthetic certificate rejected','sent'=>false,'transport_attempted'=>false,'xml_sha256'=>$r['xml_sha256'],'environment'=>$r['environment']]);exit(2);}
if(str_contains($r['xml']??'','CRASH'))exit(9);
$ok=!str_contains($r['xml']??'','INVALID');$send=($r['action']??'')==='send';
$result=['code'=>$ok?0:610301006,'message'=>$ok?'Offline fixture OK':'Offline fixture invalid','xml_sha256'=>$r['xml_sha256'],'environment'=>$r['environment'],'sent'=>$send&&$ok,'transport_attempted'=>$send&&$ok,'transfer_ticket'=>$send&&$ok?'synthetic-fixture-ticket':'','response_xml'=>'<fixture/>','server_xml'=>'<fixture/>'];
if(($r['data_version']??'')!=='Bilanz_6_9')$result['pdf_b64']=base64_encode('%PDF-1.7 synthetic fixture, not a real tax document');
echo json_encode($result);
