<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,Documents,OutgoingEInvoice,Iban};

/** Read an accepted XML original before preparing a distinct current export. */
final class OutgoingXInvoiceForm
{
    public const FIELDS=['invoice_number','buyer_reference','seller_name','seller_street','seller_city','seller_postcode','seller_email','seller_contact','seller_phone','seller_tax_number','seller_register','buyer_name','buyer_street','buyer_city','buyer_postcode','buyer_email','delivery_date','iban'];

    private static function read(PDO $db,callable $operation):mixed
    {
        $own=!$db->inTransaction();if($own)$db->beginTransaction();
        try{return $operation();}finally{if($own&&$db->inTransaction())$db->rollBack();}
    }

    public static function target(array $post,array $query):string
    {
        $id=$post['entry_id']??null;$version=$post['entry_version']??null;
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_string($version)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version))throw new DomainException('Ursprüngliche Rechnung und geprüften Versionsstand vollständig prüfen.',422);
        if(($query['view']??null)!=='finance'||($query['tab']??'receipts')!=='receipts'||($query['entry']??null)!==$id)throw new DomainException('Dieses XML-Formular gehört zu einer anderen Rechnung.',409);
        return $id;
    }

    public static function requested(array $post):bool
    {
        return array_key_exists('xinvoice_current',$post)||array_key_exists('xinvoice_adopt',$post)||array_key_exists('xinvoice_next',$post);
    }

    public static function current(PDO $db,string $actor,string $id):array
    {
        return self::read($db,static function()use($db,$actor,$id):array{
            $service=new OutgoingEInvoice($db,$actor);$source=$service->source($id);
            return ['source'=>$source,'defaults'=>$service->defaults($id),'hash'=>hash('sha256',json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
        });
    }

    public static function state(string $id,?array $current,array $post):array
    {
        $own=($post['action']??null)==='outgoing-einvoice-save'&&($post['entry_id']??null)===$id;
        $input=$own&&is_array($post['xinvoice']??null)?$post['xinvoice']:[];$values=[];$invalid=[];
        foreach([...self::FIELDS,'vat_reason','confirmed']as$key){
            if($own){$value=$input[$key]??'';if(!is_string($value)){$invalid[]=$key;$value='';}elseif(!mb_check_encoding($value,'UTF-8'))$invalid[]=$key;if(in_array($key,self::FIELDS,true)&&!array_key_exists($key,$input))$invalid[]=$key;}
            else $value=$current['defaults'][$key]??'';
            $values[$key]=$value;
        }
        $version=$own?(is_string($post['entry_version']??null)?$post['entry_version']:''):(string)($current['source']['entry']['version']??'');
        $hash=$own?(is_string($post['xinvoice_source_hash']??null)?$post['xinvoice_source_hash']:''):($current['hash']??'');
        return ['own'=>$own,'values'=>$values,'invalid'=>array_values(array_unique($invalid)),'version'=>$version,'hash'=>$hash,'changed'=>$current===null||$version!==(string)$current['source']['entry']['version']||!hash_equals($current['hash'],$hash)];
    }

    /** Current financial and document read rights still guard every old original. */
    private static function stored(PDO $db,string $actor,string $id,string $document):array
    {
        $entry=(new Finance($db,$actor))->entry($id);$docs=new Documents($db,$actor);$doc=$docs->document($document);$file=$docs->file($document,1);
        if($doc['club_id']!==$entry['club_id']||$doc['origin']!=='generated'||$doc['kind']!=='export'||$file['mime']!=='application/xml'||!hash_equals($file['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Das gespeicherte XML-Original ist nicht unverändert verfügbar.',409);
        try{$snapshot=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Kein passendes gespeichertes XML-Ergebnis.',404);}
        if(($snapshot['kind']??null)!=='outgoing_einvoice'||($snapshot['source']['entry']['id']??null)!==$id||($snapshot['validator']['state']??null)!=='valid'||!is_array($snapshot['fields']??null))throw new DomainException('Kein passendes gespeichertes XML-Ergebnis.',404);
        $version=(string)($snapshot['source']['entry']['version']??'');if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version))throw new DomainException('Kein passendes gespeichertes XML-Ergebnis.',404);
        $key=substr(hash('sha256',$id.'|'.$version.'|'.$file['bytes']),0,32);
        $q=$db->prepare("SELECT r.document_id FROM document_requests r JOIN document_links l ON l.club_id=r.club_id AND l.document_id=r.document_id WHERE r.club_id=? AND r.request_key=? AND r.document_id=? AND l.source_type='entry' AND l.source_id=?");$q->execute([$entry['club_id'],$key,$document,$id]);if($q->fetchColumn()!==$document)throw new DomainException('Kein passendes gespeichertes XML-Ergebnis.',404);
        return ['document'=>$doc,'file'=>$file,'snapshot'=>$snapshot,'fields'=>$snapshot['fields'],'source_version'=>$version,'current_version'=>(string)$entry['version']];
    }

    public static function receiptAt(PDO $db,string $actor,string $id,string $document):array
    {
        return self::read($db,static fn():array=>self::stored($db,$actor,$id,$document));
    }

    public static function receipt(PDO $db,string $actor,string $id,array $post):?array
    {
        if(($post['action']??null)!=='outgoing-einvoice-save'||($post['entry_id']??null)!==$id||!is_string($post['entry_version']??null)||!is_array($post['xinvoice']??null)||($post['xinvoice']['confirmed']??null)!=='1')return null;
        foreach(self::FIELDS as$key)if(!is_string($post['xinvoice'][$key]??null)||!mb_check_encoding($post['xinvoice'][$key],'UTF-8')||trim($post['xinvoice'][$key])===''||preg_match('/[\x00-\x1f]/',$post['xinvoice'][$key]))return null;
        return self::read($db,static function()use($db,$actor,$id,$post):?array{
            $entry=(new Finance($db,$actor))->entry($id);
            $q=$db->prepare("SELECT DISTINCT d.id,d.created_at FROM documents d JOIN document_links l ON l.club_id=d.club_id AND l.document_id=d.id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=1 WHERE d.club_id=? AND l.source_type='entry' AND l.source_id=? AND d.kind='export' AND d.origin='generated' AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(f.snapshot),f.snapshot,'{}'),'$.kind'))='outgoing_einvoice' AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(f.snapshot),f.snapshot,'{}'),'$.source.entry.version'))=? ORDER BY d.created_at DESC,d.id");$q->execute([$entry['club_id'],$id,$post['entry_version']]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN)as$document){
                try{$result=self::stored($db,$actor,$id,$document);}catch(DomainException){continue;}
                $values=[];foreach(self::FIELDS as$key)$values[$key]=trim($post['xinvoice'][$key]);$values['iban']=Iban::normalize($values['iban']);
                $reason=$result['snapshot']['source']['issued']['vat_reason']??($post['xinvoice']['vat_reason']??'');if(!is_string($reason))continue;$values['vat_reason']=trim($reason);
                $saved=$result['fields'];ksort($saved);ksort($values);if($saved===$values)return $result;
            }
            return null;
        });
    }

    public static function assertCurrent(PDO $db,string $actor,array $post,array $query):array
    {
        $id=self::target($post,$query);$current=self::current($db,$actor,$id);$state=self::state($id,$current,$post);
        if($state['invalid'])throw new DomainException('Die eigenen XML-Angaben sind unvollständig oder haben ein ungültiges Format.',422);
        if($state['changed'])throw new DomainException('Der gespeicherte Rechnungsstand hat sich geändert. Zuerst die aktuelle Fassung vergleichen und bewusst übernehmen.',409);
        return $current;
    }

    public static function handle(PDO $db,string $actor,array &$post,array $query):array
    {
        $id=self::target($post,$query);$commands=array_intersect(['xinvoice_current','xinvoice_adopt','xinvoice_next'],array_keys($post));
        if(count($commands)!==1||$post[$commands[array_key_first($commands)]]!=='1')throw new DomainException('Genau eine angebotene XML-Ansicht wählen.',422);
        if(array_key_exists('xinvoice_next',$post)){
            if(!is_string($post['xinvoice_receipt']??null))throw new DomainException('Zuerst das gespeicherte XML-Ergebnis lesen.',422);
            $accepted=self::receiptAt($db,$actor,$id,$post['xinvoice_receipt']);
            $current=self::current($db,$actor,$id);$post['entry_version']=(string)$current['source']['entry']['version'];$post['xinvoice_source_hash']=$current['hash'];
            if(!is_array($post['xinvoice']??null))$post['xinvoice']=$accepted['fields'];$post['xinvoice']['confirmed']='';
            return ['notice'=>'Neue XML-Erfassung bewusst vorbereitet. Angaben prüfen und vor dem gesonderten Speichern erneut bestätigen.'];
        }
        $current=self::current($db,$actor,$id);
        if(array_key_exists('xinvoice_current',$post))return ['comparison'=>$current,'notice'=>'Aktuelle XML-Grundlage gelesen. Deine vollständigen Angaben und die ursprünglich geprüfte Version bleiben erhalten.'];
        if(!is_string($post['xinvoice_compare_hash']??null)||!hash_equals($current['hash'],$post['xinvoice_compare_hash']))throw new DomainException('Der verglichene XML-Stand hat sich geändert. Zuerst erneut vergleichen.',409);
        $post['entry_version']=(string)$current['source']['entry']['version'];$post['xinvoice_source_hash']=$current['hash'];if(is_array($post['xinvoice']??null))$post['xinvoice']['confirmed']='';
        return ['notice'=>'Verglichene XML-Grundlage übernommen. Deine Angaben bleiben erhalten; vor dem Speichern bitte bewusst erneut bestätigen.'];
    }
}
