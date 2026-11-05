<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
final class DocumentPreview
{
    public static function letter(Settings $settings,array $input):array
    {
        foreach(['recipient'=>600,'subject'=>200,'body'=>20000,'date'=>10] as $key=>$limit){if(!is_string($input[$key]??null)||trim($input[$key])===''||strlen($input[$key])>$limit)throw new DomainException('Bitte Empfänger, Datum, Betreff und Brieftext prüfen.');}
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$input['date']);if(!$date||$date->format('Y-m-d')!==$input['date'])throw new DomainException('Briefdatum prüfen.');
        $document=self::build($settings);$document['kind']='letter';$document['recipient']=trim($input['recipient']);$document['date']=$date->format('d.m.Y');$document['title']=trim($input['subject']);$document['text']=['message'=>trim($input['body'])];unset($document['positions'],$document['net'],$document['tax'],$document['gross']);return $document;
    }
    public static function build(Settings $settings,?array $paper=null,string $group='texts_invoices',string $template='invoice',?array $templates=null,?array $valuesOverride=null):array
    {
        if(!in_array($group,['texts_invoices','texts_contributions','texts_donations'],true))throw new DomainException('Ungültige Textgruppe.');
        $paper=$settings->paperForDocument($paper);$values=$valuesOverride??$settings->previewValues();
        $contribution=$group==='texts_contributions';$values['BEZEICHNUNG']=$contribution?'Vereinsbeitrag · Muster':($group==='texts_donations'?'Geldspende · Muster':'Vereinsleistung · Muster');
        $templates=SettingsSchema::normalize($group,$templates??$settings->section($group)['data']);if(!isset($templates[$template]))throw new DomainException('Textbaustein nicht verfügbar.');
        $text=$templates[$template];foreach(['subject','message','intro','closing'] as $f)$text[$f]=SettingsSchema::interpolate($text[$f],$values);
        foreach(['footer_left','footer_middle','footer_right'] as $f)$paper[$f]=SettingsSchema::interpolate($paper[$f],$values);
        $assets=[];foreach(['logo'] as $kind)if($paper[$kind]!==''){$asset=$settings->asset($paper[$kind]);$assets[$kind]=['mime'=>$asset['mime'],'base64'=>base64_encode($asset['bytes'])];}
        return ['payment_reference'=>$group!=='texts_donations'&&$template!=='debit'?$values['RECHNUNGSNUMMER']:'','preview_group'=>$group,'preview_template'=>$template,'preview_style'=>in_array($template,['reminder','debit','donation','collective'],true)?'message':'positions','paper'=>$paper,'values'=>$values,'text'=>$text,'assets'=>$assets,'title'=>match($template){'reminder'=>$contribution?'Beitragserinnerung':'Rechnungserinnerung','debit'=>$contribution?'Lastschriftvorankündigung zum Vereinsbeitrag':'Lastschriftvorankündigung','donation'=>'Spendenbegleitschreiben','collective'=>'Sammelspendenbegleitschreiben',default=>$contribution?'Beitragsrechnung':'Rechnung'},'positions'=>[['description'=>$values['BEZEICHNUNG'],'quantity'=>'1','unit'=>'Stück','tax'=>'0 %','gross'=>'120,00 €']],'net'=>'120,00 €','tax'=>'0,00 €','gross'=>'120,00 €'];
    }
    public static function pdf(array $document):string
    {
        $root=dirname(dirname(__DIR__, 1),2).'/.local/vereinsverwaltung';$config=$root.'/pdf-runtime.json';
        $python='';
        if(!empty($document['general_invoice'])){
            $python=getenv('VEREIN_INVOICE_PYTHON')?:'';
            $invoiceConfig=$root.'/invoice-pdf-runtime.json';
            if($python===''&&is_file($invoiceConfig)){
                $python=json_decode((string)file_get_contents($invoiceConfig),true,512,JSON_THROW_ON_ERROR)['python']??'';
                if($python!==''&&!str_starts_with($python,'/')&&!preg_match('~^[A-Za-z]:[\\\\/]~',$python))$python=dirname($invoiceConfig).'/'.$python;
            }
        }
        $python=$python?:getenv('VEREIN_PYTHON');$python=$python?:'';if($python===''&&is_file($config))$python=json_decode((string)file_get_contents($config),true)['python']??'';
        if($python===''||!is_file($python))throw new DomainException('PDF-Laufzeit fehlt. VEREIN_PYTHON auf Python mit ReportLab setzen.');
        $dir=$root.'/pdf-tmp';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new DomainException('PDF-Arbeitsordner fehlt.');$base=$dir.'/'.bin2hex(random_bytes(16));
        $input=json_encode($document,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(strlen($input)>6500000)throw new DomainException('Dokument zu groß.');
        $process=null;$pipes=[];
        try{
            $renderer=isset($document['volunteer_general'])?'render-volunteer-general.py':(isset($document['collection'])?'render-donation-collection.py':(isset($document['volunteer_form'])?'render-volunteer-form.py':(isset($document['invitation_batch'])?'render-invitations.py':(isset($document['donation_form'])?'render-donation-form.py':(isset($document['record'])?'render-record.py':'render-document.py')))));
            if(isset($document['activity_review']))$renderer='render-activity-report.py';
            if(isset($document['event_voucher_cards']))$renderer='render-event-vouchers.py';
            if(isset($document['balance_report']))$renderer='render-balance-report.py';
            if(isset($document['document_workflow']))$renderer='render-document-workflow.py';
            $process=proc_open([$python,'-X','utf8',dirname(__DIR__, 1).'/../tools/'.$renderer],[0=>['pipe','r'],1=>['file',$base.'.pdf','w'],2=>['file',$base.'.err','w']],$pipes,dirname(__DIR__, 1),null,['bypass_shell'=>true]);if(!is_resource($process))throw new DomainException('PDF-Erstellung nicht gestartet.');
            $written=0;while($written<strlen($input)){$n=fwrite($pipes[0],substr($input,$written,65536));if($n===false||$n===0)throw new DomainException('PDF-Daten konnten nicht übergeben werden.');$written+=$n;}fclose($pipes[0]);unset($pipes[0]);
            $deadline=microtime(true)+20;do{$status=proc_get_status($process);if(!$status['running'])break;if(microtime(true)>$deadline){proc_terminate($process);throw new DomainException('PDF-Erstellung dauerte zu lange.');}usleep(50000);}while(true);
            proc_close($process);$process=null;$bytes=(string)file_get_contents($base.'.pdf');
            if($status['exitcode']!==0||!str_starts_with($bytes,'%PDF-')||strlen($bytes)>15000000)throw new DomainException('PDF konnte nicht erstellt werden. Bitte Textlängen, Bilder und PDF-Laufzeit prüfen.');return $bytes;
        }finally{foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach(['.pdf','.err'] as $ext)if(is_file($base.$ext))unlink($base.$ext);}
    }
}
