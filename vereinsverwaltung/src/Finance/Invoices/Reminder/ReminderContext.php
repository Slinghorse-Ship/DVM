<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\SettingsSchema;
/** Current approved reminder text with the issued invoice's payment destination. */
final class ReminderContext
{
    public static function read(PDO $db,string $actor,string $invoice,string $day):array
    {
        $own=!$db->inTransaction();
        try{
            if($own)$db->exec('START TRANSACTION');$original=ReminderSource::read($db,$actor,$invoice,$day);$club=$original['invoice']['club_id'];
            $rows=Db::run($db,'SELECT section,payload,version FROM club_settings WHERE club_id=? AND section IN (?,?) ORDER BY section FOR SHARE NOWAIT',[$club,'texts_invoices','account:'.$actor])->fetchAll();$states=array_column($rows,null,'section');
            $templateState=$states['texts_invoices']??null;$templates=$templateState?json_decode($templateState['payload'],true,64,JSON_THROW_ON_ERROR):SettingsSchema::defaults('texts_invoices');$templates=SettingsSchema::normalize('texts_invoices',$templates);
            $profileState=$states['account:'.$actor]??null;$profile=$profileState?json_decode($profileState['payload'],true,64,JSON_THROW_ON_ERROR):SettingsSchema::defaults('account');$profile=array_replace(SettingsSchema::defaults('account'),$profile);
            $data=$original['invoice']['data'];$issuer=$data['issuer'];$bank=$issuer['bank'];$snapshot=$original['snapshot'];
            $date=static fn(string $value):string=>(new \DateTimeImmutable($value))->format('d.m.Y');
            $values=['VEREINSNAME'=>(string)$issuer['club']['name'],'BRIEFANREDE'=>'Guten Tag '.$data['buyer_name'].',','ZAHLBETRAG'=>number_format($original['open_cents']/100,2,',','.').' €','VEREINS_IBAN'=>(string)($bank['iban']??''),'VEREINS_BIC'=>(string)($bank['bic']??''),'KONTOINHABER'=>(string)($bank['holder']??''),'GLAEUBIGER_ID'=>(string)($bank['creditor']??''),'PAYPAL_LINK'=>(string)($bank['paypal']??''),'BEARBEITER'=>(string)$profile['display_name'],'DATUM'=>$date($day),'RECHNUNGSNUMMER'=>$original['invoice']['number'],'VERWENDUNGSZWECK'=>$original['invoice']['number'],'RECHNUNGSDATUM'=>$date($data['document_date']),'LIEFERDATUM'=>$date($data['delivery_date']),'FAELLIGKEIT'=>$date($data['due_date']),'BEZEICHNUNG'=>$data['description'],'ZAHLUNG_FUER'=>$data['description']];
            // The already issued sender, layout and logo remain original. No new signature is applied.
            $paper=$snapshot['paper'];$paper['signature']='';$assets=array_intersect_key($snapshot['assets']??[],['logo'=>true]);
            $file=Db::run($db,'SELECT name,mime,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=1 FOR SHARE NOWAIT',[$club,$original['invoice']['document_id']])->fetch();if(!$file||$file['sha256']!==$original['source']['file']['sha256'])throw new DomainException('Genau das ursprüngliche Rechnungs-PDF erneut prüfen.',409);
            $metadata=[];foreach(['template'=>$templateState,'profile'=>$profileState]as$name=>$row)$metadata[$name]=['version'=>(int)($row['version']??0),'payload_sha256'=>$row?hash('sha256',$row['payload']):null];
            $context=['invoice'=>$original['invoice'],'original'=>$original,'template'=>$templates['reminder'],'values'=>$values,'paper'=>$paper,'assets'=>$assets,'recipient'=>$snapshot['recipient'],'day'=>$day,'settings'=>$metadata,'file'=>$file];
            if($own)$db->commit();return $context;
        }catch(\Throwable $failure){if($own&&$db->inTransaction())$db->rollBack();throw $failure;}
    }
}
