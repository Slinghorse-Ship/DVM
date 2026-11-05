<?php declare(strict_types=1);
$periodRecoveryFormHtml='';
if(($periodRecoveryAllowed??false)&&($periodRecoveryOutput??'')!==''){
    $periodRecoveryDom=new DOMDocument('1.0','UTF-8');$periodRecoveryLibxml=libxml_use_internal_errors(true);
    try{$periodRecoveryDom->loadHTML('<?xml encoding="UTF-8">'.$periodRecoveryOutput,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
    finally{libxml_clear_errors();libxml_use_internal_errors($periodRecoveryLibxml);}
    $periodRecoveryValue=static function(string$name,array$post):mixed{if(!preg_match('/\A([^\[\]]+)((?:\[[^\[\]]+\])*)\z/D',$name,$m))return null;$value=$post[$m[1]]??null;preg_match_all('/\[([^\[\]]+)\]/',$m[2],$keys);foreach($keys[1]as$key){if(!is_array($value)||!array_key_exists($key,$value))return null;$value=$value[$key];}return$value;};
    foreach($periodRecoveryDom->getElementsByTagName('form')as$periodRecoveryForm){
        if(strtolower($periodRecoveryForm->getAttribute('method'))!=='post')continue;$periodRecoveryNamed=[];foreach($periodRecoveryForm->getElementsByTagName('input')as$node)$periodRecoveryNamed[$node->getAttribute('name')]=$node->getAttribute('value');
        if(($periodRecoveryNamed['action']??'')!==$periodRecoveryAction)continue;
        if($periodRecoveryAction==='balance-period-reverse'&&($periodRecoveryNamed['entry']??'')!==input($old,'entry'))continue;
        foreach(['input','select','textarea']as$tag)foreach($periodRecoveryForm->getElementsByTagName($tag)as$node){
            $name=$node->getAttribute('name');if($name===''||$name==='csrf')continue;$value=$periodRecoveryValue($name,$old);
            if($tag==='input'){$type=strtolower($node->getAttribute('type'));if(in_array($type,['checkbox','radio'],true)){if(is_string($value)&&$value===$node->getAttribute('value'))$node->setAttribute('checked','checked');else$node->removeAttribute('checked');}elseif(is_string($value))$node->setAttribute('value',$value);}
            elseif($tag==='textarea'&&is_string($value)){$node->textContent=$value;}
            elseif($tag==='select'&&is_string($value)){$found=false;foreach($node->getElementsByTagName('option')as$option){if($option->getAttribute('value')===$value){$option->setAttribute('selected','selected');$found=true;}else$option->removeAttribute('selected');}if(!$found){$option=$periodRecoveryDom->createElement('option');$option->setAttribute('value',$value);$option->setAttribute('selected','selected');$option->textContent=$periodText('Bisherige Auswahl anhand der Belege prüfen.');$node->appendChild($option);}}
        }
        $periodRecoveryForm->setAttribute('class',trim($periodRecoveryForm->getAttribute('class').' card'));$periodRecoveryForm->setAttribute('data-period-journal-operation',substr($periodRecoveryAction,15));$periodRecoveryForm->setAttribute('data-unsaved-changes','');$periodRecoveryFormHtml=$periodRecoveryDom->saveHTML($periodRecoveryForm);break;
    }
}
$periodRecoveryLabels=['balance-period-create'=>'Neuen Zeitraum anlegen','balance-period-opening'=>'Anfangsbestände prüfen','balance-period-account'=>'Buchungskonto ergänzen','balance-period-post'=>'Zusätzliche Buchung erfassen','balance-period-reverse'=>'Buchung stornieren','balance-period-close'=>'Abschluss bestätigen'];
?>
<link rel="stylesheet" href="tax-guided.css?v=3"><div class="content" data-tax-guided data-period-journal-recovery>
<div class="page-heading"><h1><?= e($periodText($periodRecoveryLabels[$periodRecoveryAction]??'Jahresabschluss · Bilanz')) ?></h1></div>
<p role="status"><?= e($periodText('Diese Eingaben sind noch nicht als neuer Buchungsstand gespeichert. Der ursprüngliche Bezug bleibt erhalten.')) ?></p>
<?php if(isset($periodRecoveryMessage)): ?><p role="alert"><?= e($periodText($periodRecoveryMessage)) ?></p><?php endif ?>
<?php if(is_array($periodCurrent??null)): ?><section class="card" data-period-journal-current><h2><?= e($periodText('Aktueller Stand dieses Abschlusszeitraums')) ?></h2><p><?= e(implode('.',array_reverse(explode('-',$periodCurrent['period']['start']))).' – '.implode('.',array_reverse(explode('-',$periodCurrent['period']['end'])))) ?></p><p><?= e($periodText($periodCurrent['state']==='closed'?'Buchungsstand festgeschrieben':($periodCurrent['state']==='setup'?'Anfangsbestände prüfen':'Buchungsstand offen'))) ?></p><p><?= e($periodText('Ergebnis dieses Zeitraums')) ?>: <?= e(number_format($periodCurrent['calculation']['annual_result_cents']/100,2,',','.').' €') ?></p><a class="btn btn-outline" href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodCurrent['id']])) ?>"><?= e($periodText('Aktuellen Abschlusszeitraum öffnen')) ?></a></section><?php endif ?>
<?php if($periodRecoveryFormHtml===''): ?><p><?= e($periodText('Die ursprünglichen Angaben bleiben lesbar. Für neue Buchungen den aktuellen Stand und die aktuellen Rechte prüfen.')) ?></p><?php else: ?><p><?= e($periodText('Nur dieser ursprüngliche Vorgang kann hier erneut geprüft werden. Ein neuer Buchungsstand wird nicht automatisch eingesetzt.')) ?></p><?= $periodRecoveryFormHtml ?><?php endif ?>
<?php
$originalInputPost=$old;$originalInputText=$periodText;$originalInputOpen=$periodRecoveryFormHtml==='';$originalInputAccounts=$r['accounts']??[];$originalInputVouchers=[];foreach($r['entries']??[]as$key=>$entry)$originalInputVouchers[$key]=$entry['description'];
$originalInputConfirmations=[];if(in_array($periodRecoveryAction,['balance-period-create','balance-period-opening'],true))$originalInputConfirmations['reviewed']='Ausdrückliche Bestätigung';if($periodRecoveryAction==='balance-period-create')$originalInputConfirmations['day_allocation_reviewed']='Interne Tagesaufteilung';if($periodRecoveryAction==='balance-period-close')$originalInputConfirmations=['complete'=>'Vollständigkeit der Belege','valuation'=>'Bewertung von Vermögen und Schulden','cutoff'=>'Zuordnung zum Abschlusszeitraum','tax'=>'Steuerliche Zuordnung'];
require __DIR__.'/balance-original-inputs.php';
?></div>
<?php unset($periodRecoveryFormHtml,$periodRecoveryOutput,$periodRecoveryDom,$periodRecoveryLibxml,$periodRecoveryValue,$periodRecoveryForm,$periodRecoveryNamed,$periodRecoveryLabels); ?>
