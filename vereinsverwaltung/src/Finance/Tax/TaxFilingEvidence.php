<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Two ELSTER jobs, one explicit user instruction. Never resend an accepted declaration. */
final class TaxFilingEvidence
{
    public function __construct(private PDO $db,private string $actor){}
    public static function needsEvidence(array $parent):bool
    {
        $s=$parent['form_data']['auto_preparation']['source_snapshot']??[];
        return !empty($s['sources'])||(($s['activity_review']['approved']??false)===true&&($s['activity_review']['kind']??'')==='tax');
    }
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    public function linked(string $id):?array
    {
        $f=new TaxFilings($this->db,$this->actor);$r=$f->get($id);
        $child=$this->q('SELECT evidence_id FROM tax_filing_evidence WHERE club_id=? AND filing_id=?',[$r['club_id'],$id])->fetchColumn();
        return $child?$f->get($child):null;
    }
    public function prepare(string $id,int $version):string
    {
        $f=new TaxFilings($this->db,$this->actor);$r=$f->get($id);
        if(!$f->canWrite())throw new DomainException('Finanz-Schreibrecht erforderlich.');
        if((int)$r['version']!==$version||!in_array($r['family'],['kst','euer'],true)||!in_array($r['state'],['validated','sent_test','sent_production'],true)||!$r['xml_sha256'])throw new DomainException('Zuerst die aktuelle Steuererklärung amtlich prüfen.');
        $s=TaxFormSchema::load('Belegnachreichung_24');$fields=[];
        $get=static fn(string $path):string=>(string)($r['form_data']['fields'][TaxFormSchema::key($path)]??'');
        $values=['Ordnungsbegriff/Steuernummer'=>$get('Vorsatz/StNr'),
            'Persoenliche_Daten/Person_A/SteuerpflichtigerTyp'=>'NichtNatPerson',
            'Persoenliche_Daten/Person_A/Name'=>$get($r['family']==='kst'?'KSt1/Allgemein/E8000101':'EUER/Allg/E6000016'),
            'Belege/Jahr'=>(string)$r['tax_year'],
            'Belege/Steuerarten[0]/Steuerart'=>$r['family']==='kst'?'0610':'2150',
            'Belege/Text'=>'Nachweise zur '.($r['family']==='kst'?'Körperschaftsteuererklärung':'Einnahmenüberschussrechnung').' '.$r['tax_year'].'. Vereinsmanager-Auftrag: '.$r['id'].'.'];
        foreach($values as $path=>$value)if($value!=='')$fields[TaxFormSchema::key($path)]=$value;
        $fields=$s->normalize($fields);
        $lock='tax-bundle-'.substr(hash('sha256',$r['club_id'].':'.$id),0,40);
        if((int)$this->q('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Dieser Auftrag wird bereits bearbeitet.',503);
        try{return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($f,$r,$id,$version,$fields):string{
            if(!$f->canWrite())throw new DomainException('Finanz-Schreibrecht erforderlich.',403);
            $locked=$this->q('SELECT version FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$r['club_id'],$id])->fetchColumn();
            if((int)$locked!==$version)throw new DomainException('Erklärung wurde inzwischen geändert.');
            $old=$this->linked($id);
            if($old){$childVersion=$this->q('SELECT version FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$r['club_id'],$old['id']])->fetchColumn();if((int)$childVersion!==(int)$old['version'])throw new DomainException('Der Belegauftrag wurde inzwischen geändert.',409);}
            if($old&&!in_array($old['state'],['draft','validated','failed'],true))throw new DomainException('Der Belegauftrag wurde bereits übermittelt oder wird noch geklärt.');
            if($old&&($old['form_data']['parent_binding']['sha256']??null)===$r['xml_sha256']){return $old['id'];}
            $child=$old?$old['id']:$f->create('Belegnachreichung_24',$r['environment'],(int)$r['tax_year']);
            $header=$r['form_data']['header'];
            if($r['environment']==='production'){
                $email=$old['form_data']['header']['feedback_email']??(TaxClubData::stored($this->db,$this->actor)['club']['email']??'');
                if(is_string($email)&&strlen($email)<=128&&filter_var($email,FILTER_VALIDATE_EMAIL))$header['feedback_email']=$email;
            }
            $attachments=$old['form_data']['attachments']??(new TaxAttachments($this->db,$this->actor))->fromSnapshot($r['form_data']['auto_preparation']['source_snapshot']??[],(int)$r['tax_year']);
            $data=['fields'=>$fields,'counts'=>[],'header'=>$header,'attachments'=>$attachments,
                'parent_binding'=>['id'=>$id,'sha256'=>$r['xml_sha256'],'fields'=>$fields]];
            $updated=$this->q("UPDATE tax_filings SET form_data=?,scope_snapshot=?,state='draft',version=version+1,xml_data=NULL,xml_sha256=NULL,content_sha256=NULL,runtime_sha256=NULL,validation=NULL,pdf=NULL,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state IN ('draft','validated','failed')",[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($r['scope_snapshot'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$this->actor,gmdate('c'),$r['club_id'],$child,(int)($old['version']??1)]);if($updated->rowCount()!==1)throw new DomainException('Der Belegauftrag wurde inzwischen geändert.',409);
            if(!$old)$this->q('INSERT INTO tax_filing_evidence(club_id,filing_id,evidence_id) VALUES(?,?,?)',[$r['club_id'],$id,$child]);
            return $child;
        });}catch(\PDOException$e){if(in_array((int)($e->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Dieser Auftrag wird bereits bearbeitet.',503,$e);throw$e;}
        finally{$this->q('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function assertCurrent(array $child,bool $sending=false):void
    {
        $binding=$child['form_data']['parent_binding']??null;if($binding===null)return;
        $f=new TaxFilings($this->db,$this->actor);$parent=$f->get($binding['id']);$linked=$this->linked($parent['id']);
        if(!$linked||$linked['id']!==$child['id']||$parent['environment']!==$child['environment']||$parent['tax_year']!==$child['tax_year']||!hash_equals($binding['sha256'],(string)$parent['xml_sha256'])||$child['form_data']['fields']!==$binding['fields'])throw new DomainException('Steuererklärung wurde geändert. Belege bei der Erklärung erneut vorbereiten.');
        foreach(['office','supplier'] as $key)if($child['form_data']['header'][$key]!==$parent['form_data']['header'][$key])throw new DomainException('Absender und Finanzamt müssen zur zugehörigen Erklärung passen.');
        if(!in_array($parent['state'],$sending?['sent_test','sent_production']:['validated','sent_test','sent_production'],true))throw new DomainException('Die zugehörige Steuererklärung muss zuerst bestätigt übermittelt sein.');
    }
    public function send(string $id,int $version,#[\SensitiveParameter] string $certificate,#[\SensitiveParameter] string $pin,string $confirmation,bool $reviewed,bool $synthetic,string $childId='',int $childVersion=0):array
    {
        $f=new TaxFilings($this->db,$this->actor);$parent=$f->get($id);
        $lock='tax-bundle-'.substr(hash('sha256',$parent['club_id'].':'.$id),0,40);
        if((int)$this->q('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Dieser Auftrag wird bereits bearbeitet.');
        try{
        $child=$this->linked($id);
        if(!$child&&self::needsEvidence($parent))throw new DomainException('Die ausgewählten Nachweise und den freigegebenen Tätigkeitsbericht zuerst unter PDF-Belege mitsenden prüfen.');
        if($child){
            if($child['id']!==$childId||(int)$child['version']!==$childVersion)throw new DomainException('Die aktuelle Belegauswahl und beide Vorschauen prüfen.');
            $f->ready($childId,$childVersion,$confirmation,$reviewed,$synthetic);
        }elseif($childId!=='')throw new DomainException('Belegauftrag gehört nicht zur Erklärung.');
        $result=$f->send($id,$version,$certificate,$pin,$confirmation,$reviewed,$synthetic);
        if($child&&in_array($result['state'],['sent_test','sent_production'],true)){
            // A failure of the second request must not hide the first request's success.
            try{$result['evidence']=$f->send($childId,$childVersion,$certificate,$pin,$confirmation,$reviewed,$synthetic);}
            catch(\Throwable){$result['evidence']=['state'=>'attention'];}
            $result['evidence_id']=$childId;
        }
        return $result;
        }finally{$this->q('SELECT RELEASE_LOCK(?)',[$lock]);if(function_exists('sodium_memzero')){sodium_memzero($certificate);sodium_memzero($pin);}}
    }
}
