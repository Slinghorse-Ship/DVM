<?php declare(strict_types=1);
// Included only after the central CSRF check. No certificates in the staged upload system.
$filings=new \Kulturhus\Verein\TaxFilings($db,(string)$_SESSION['actor']);
$route=['view'=>'finance','tab'=>'tax','tax_filings'=>'1'];$fid=input($_POST,'filing');$version=(int)input($_POST,'version');
if(input($_POST,'tax_year')!=='')$route['tax_year']=input($_POST,'tax_year');
switch($action){
case 'tax-filing-balance-create':
case 'tax-filing-balance-generate':
case 'tax-filing-balance-save':
case 'tax-filing-balance-partnership':
case 'tax-filing-balance-equalization':
case 'tax-filing-balance-financial-reserve':
case 'tax-filing-balance-financial-reserve-withdraw':
case 'tax-filing-balance-basis-6b':
case 'tax-filing-balance-basis-7g':
case 'tax-filing-balance-special-7b':
case 'tax-filing-balance-special-7g':
case 'tax-filing-balance-pension-offsets':
case 'tax-filing-balance-pool-original':
case 'tax-filing-balance-own-intangible':
case 'tax-filing-balance-approve':
case 'tax-filing-balance-revision':
case 'tax-filing-balance-capital':
case 'tax-filing-balance-adjust':
case 'tax-filing-balance-carry':
case 'tax-filing-balance-asset':
    $localBalance=\Kulturhus\Verein\BalanceFilingActions::apply($db,(string)$_SESSION['actor'],$action,$_POST);
    $fid=$localBalance['filing']['id'];$route['tax_year']=(string)$localBalance['filing']['tax_year'];$_SESSION['notice']=$localBalance['notice'];break;
case 'tax-filing-certificate-remove':
    if(input($_POST,'remove_confirmed')!=='1')throw new DomainException('Entfernen des hinterlegten Zertifikats bestätigen.');
    (new \Kulturhus\Verein\TaxCertificateVault($db,(string)$_SESSION['actor']))->remove(input($_POST,'certificate_revision'));
    $_SESSION['notice']='Vereinszertifikat entfernt.';$route['tax_certificate']='1';break;
case 'tax-filing-certificate-save':
    $cert='';$pin=is_string($_POST['certificate_pin']??null)?$_POST['certificate_pin']:'';$upload=$_FILES['tax_certificate']??[];unset($_POST['certificate_pin']);
    try{
        $vault=new \Kulturhus\Verein\TaxCertificateVault($db,(string)$_SESSION['actor']);
        if(!$vault->allowed())throw new DomainException('Berechtigter Vorstand erforderlich.');
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])||filesize($upload['tmp_name'])>1048576)throw new DomainException('ELSTER-Zertifikat auswählen, höchstens 1 MB.');
        $cert=(string)file_get_contents($upload['tmp_name']);unlink($upload['tmp_name']);unset($_FILES['tax_certificate']);
        $vault->save($cert,$pin,input($_POST,'certificate_revision'),input($_POST,'organization_confirmed')==='1');
        $_SESSION['notice']='Vereinszertifikat verschlüsselt gespeichert. Das Passwort wurde nicht gespeichert.';
    }finally{if(function_exists('sodium_memzero')){sodium_memzero($pin);sodium_memzero($cert);}if(is_string($upload['tmp_name']??null)&&is_uploaded_file($upload['tmp_name']))unlink($upload['tmp_name']);unset($_FILES['tax_certificate']);}
    $route['tax_certificate']='1';break;
case 'tax-filing-profile':
    if(!is_array($_POST['answers']??null))throw new DomainException('Steuerprofil prüfen.');
    $year=(int)input($_POST,'tax_year');$filings->saveProfile($year,$_POST['answers'],$version);$route['tax_year']=(string)$year;break;
case 'tax-filing-create':
    $fid=$filings->create(input($_POST,'data_version'),input($_POST,'environment','test'),(int)input($_POST,'tax_year'));break;
case 'tax-filing-evidence':
    $fid=(new \Kulturhus\Verein\TaxFilingEvidence($db,(string)$_SESSION['actor']))->prepare($fid,$version);break;
case 'tax-filing-save':case 'tax-filing-row':case 'tax-filing-attachments':
    $fields=$_POST['fields']??[];$header=$_POST['header']??[];
    if(!is_array($fields)||!is_array($header))throw new DomainException('Formularangaben prüfen.');
    $attachments=$action==='tax-filing-attachments'?($_POST['tax_documents']??[]):null;
    if($attachments!==null&&!is_array($attachments))throw new DomainException('Auswahl der Anlagen prüfen.');
    $filings->save($fid,$version,$fields,$header,$action==='tax-filing-row'?input($_POST,'add_group'):null,$attachments);break;
case 'tax-filing-validate':
    $validation=$filings->validate($fid,$version);$_SESSION['notice']=$validation['valid']?'Amtliche Prüfung bestanden. Vorschau öffnen.':'Die amtliche Prüfung meldet offene Angaben.';break;
case 'tax-filing-send':case 'tax-filing-feedback':
    $cert='';$pin=is_string($_POST['certificate_pin']??null)?$_POST['certificate_pin']:'';$upload=$_FILES['tax_certificate']??[];unset($_POST['certificate_pin']);
    try{
        if(input($_POST,'certificate_source','upload')==='stored'){
            $pending=$filings->get($fid);
            if($pending['environment']!=='production')throw new DomainException('Vereinszertifikat nur für eigene echte Abgaben verwenden.');
            if($action==='tax-filing-send'&&($pending['state']!=='validated'||(int)$pending['version']!==$version||input($_POST,'reviewed')!=='1'||input($_POST,'confirmation')!=='SEND-PRODUCTION'))throw new DomainException('Die aktuelle Vorschau prüfen und die Abgabe bestätigen.');
            if($action==='tax-filing-feedback'&&!in_array($pending['state'],['sent_test','sent_production'],true))throw new DomainException('Zuerst Belege übermitteln.');
            $cert=(new \Kulturhus\Verein\TaxCertificateVault($db,(string)$_SESSION['actor']))->load(input($_POST,'certificate_revision'),$pin);
        }elseif(input($_POST,'certificate_source','upload')==='upload'){
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])||filesize($upload['tmp_name'])>1048576)throw new DomainException('ELSTER-Zertifikat auswählen, höchstens 1 MB.');
        $cert=(string)file_get_contents($upload['tmp_name']);unlink($upload['tmp_name']);unset($_FILES['tax_certificate']);
        }else{throw new DomainException('Zertifikat auswählen.');}
        if($action==='tax-filing-feedback'){
            $feedback=(new \Kulturhus\Verein\TaxAttachmentFeedback($db,(string)$_SESSION['actor']))->refresh($fid,$cert,$pin);
            $_SESSION['notice']=$feedback['cursor_id']!==''?'Weitere Rückmeldungen vorhanden. Abruf fortsetzen.':($feedback['items']?'Beanstandungen zu Anlagen liegen vor. Bitte unten prüfen.':'Keine Beanstandung zu diesen Anlagen gemeldet.');
        }else{
            $sent=(new \Kulturhus\Verein\TaxFilingEvidence($db,(string)$_SESSION['actor']))->send($fid,$version,$cert,$pin,input($_POST,'confirmation'),input($_POST,'reviewed')==='1',input($_POST,'synthetic')==='1',input($_POST,'evidence_id'),(int)input($_POST,'evidence_version'));
            $_SESSION['notice']=match($sent['state']){'sent_test'=>'Testabgabe bestätigt. Keine echte Steuererklärung abgegeben.','sent_production'=>'Übermittlung bestätigt. Protokoll ist gespeichert.','failed'=>'Nicht übermittelt. Fehler prüfen.',default=>'Abgabestatus unklar. Nicht erneut senden; Übermittlung prüfen.'};
            if(isset($sent['evidence']))$_SESSION['notice'].=in_array($sent['evidence']['state'],['sent_test','sent_production'],true)?' Ausgewählte PDF-Belege ebenfalls übermittelt.':' Erklärung übermittelt; Belegauftrag separat prüfen. Die Erklärung nicht erneut senden.';
        }
    }finally{if(function_exists('sodium_memzero')){sodium_memzero($pin);sodium_memzero($cert);}if(is_string($upload['tmp_name']??null)&&is_uploaded_file($upload['tmp_name']))unlink($upload['tmp_name']);unset($_FILES['tax_certificate']);}
    break;
default:throw new DomainException('Unbekannter ELSTER-Vorgang.');
}
if(in_array($action,['tax-filing-certificate-save','tax-filing-certificate-remove'],true)&&input($_POST,'certificate_return')==='tax-settings'){
    $route=['view'=>'settings','tab'=>'','section'=>'tax'];
}else{
    if($fid!=='')$route['filing']=$fid;
    foreach(['section','form_page'] as $key)if(input($_POST,$key)!=='')$route[$key]=input($_POST,$key);
}
redirectTo($route);
