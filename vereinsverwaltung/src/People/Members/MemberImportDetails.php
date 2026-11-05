<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Explicit spreadsheet details mapped onto the existing family, annual-dues and bank services. */
final class MemberImportDetails
{
    public const FIELDS=['iban'=>'IBAN (Kontonummer)','holder'=>'Kontoinhaber','bic'=>'BIC','annual_fee'=>'Jahresbeitrag (EUR)','family_key'=>'Familienkennung','family_name'=>'Familienname / Bezeichnung','family_payer'=>'Familien-Beitragszahler (ja/nein)'];
    public const ALIASES=[
        'iban'=>['IBAN','IBAN (Kontonummer)','Kontonummer','Bankverbindung'],
        'holder'=>['Kontoinhaber','Kontoinhaberin','Account holder'],
        'bic'=>['BIC','SWIFT'],
        'annual_fee'=>['Beitrag','Jahresbeitrag','Jahresbeitrag (EUR)','Mitgliedsbeitrag','Annual contribution'],
        'family_key'=>['Familienkennung','Familiengruppe','Family key'],
        'family_name'=>['Familienname / Bezeichnung','Familienbezeichnung','Family name / label'],
        'family_payer'=>['Familien-Beitragszahler (ja/nein)','Familien-Beitragszahler','Family payer'],
    ];
    public function __construct(private PDO $db,private string $actor){}
    private static function text(string $value,int $length):void
    {
        if(!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$length||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Bank-, Beitrags- und Familienangaben vollständig prüfen.');
    }
    private function q(string $sql,array $parameters=[],bool $lock=false):array
    {
        $q=$this->db->prepare($sql.($lock?' FOR SHARE NOWAIT':''));$q->execute($parameters);return$q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function review(#[\SensitiveParameter] array $rows,bool $lock=false):array
    {
        $a=Permissions::actor($this->db,$this->actor);$club=$a['club_id'];$families=[];$people=[];$rates=[];$rateFailure='';$groups=[];$bankAvailable=null;
        $needed=(bool)array_filter($rows,static fn($row)=>($row['record']['family_key']??'')!==''||($row['record']['annual_fee']??'')!=='');
        if($needed){
            foreach($this->q('SELECT f.*,c.name payer_name FROM member_families f JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE f.club_id=? ORDER BY f.id',[$club],$lock)as$family)$families[$family['id']]=$family;
            foreach($this->q('SELECT * FROM member_family_people WHERE club_id=? ORDER BY contact_id',[$club],$lock)as$person)$people[$person['contact_id']]=$person['family_id'];
            try{$rates=ContributionRates::all($this->db,$club,$lock);}catch(DomainException $failure){$rateFailure=$failure->getMessage();}
        }
        $refuse=static function(int $index,string $message)use(&$rows):void{if($rows[$index]['state']!=='review'){$rows[$index]['state']='review';$rows[$index]['note']=$message;}};
        foreach($rows as$index=>&$row){$r=&$row['record'];$r['_bank']=null;$r['_family']=null;$r['_annual_cents']=null;$r['_payer']=false;
            if($r['family_key']!=='')$groups['key:'.$r['family_key']][]=$index;
            try{
                $payer=mb_strtolower($r['family_payer']);if(!in_array($payer,['','ja','nein','yes','no','1','0'],true))throw new DomainException('Familien-Beitragszahler als ja oder nein angeben.');$r['_payer']=in_array($payer,['ja','yes','1'],true);
                foreach(self::FIELDS as$field=>$label)self::text($r[$field]??'',match($field){'family_key'=>160,'family_name','holder'=>200,default=>80});
                $bank=array_filter([$r['iban'],$r['holder'],$r['bic']],static fn($value)=>$value!=='');
                if($bank){
                    if($r['iban']===''||$r['holder']==='')throw new DomainException('Für eine Bankverbindung vollständige IBAN und Kontoinhaber eintragen. Eine alte Kontonummer ohne IBAN wird nicht umgerechnet.');
                    try{Iban::normalize($r['iban']);}catch(DomainException){throw new DomainException('IBAN mit gültiger Prüfziffer eintragen. Eine alte Kontonummer ohne IBAN reicht nicht.');}
                    $r['_bank']=MemberBankVault::canonicalPayload('save',['iban'=>$r['iban'],'holder'=>$r['holder'],'bic'=>$r['bic']]);
                    if($bankAvailable===null){try{MemberBankVault::fromEnvironment($this->db,$this->actor,ProductionRuntime::enabled())->importAvailable();$bankAvailable=true;}catch(DomainException $failure){$bankAvailable=$failure->getMessage();}}
                    if($bankAvailable!==true)throw new DomainException($bankAvailable);
                    if($row['state']==='existing')throw new DomainException('Bestehendes Mitglied: Bankdaten bleiben unverändert. Eine gewünschte Bankänderung im Mitgliederprofil prüfen.');
                    if($r['end']!==''&&$r['end']<(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'))throw new DomainException('Bankdaten nur mit aktueller Mitgliedschaft übernehmen. Ehemalige Bankdaten bleiben geschützt.');
                }
                if($r['family_key']===''){
                    if($r['family_name']!==''||$r['_payer'])throw new DomainException('Familienkennung für die ausdrücklich gewünschte Familienzuordnung eintragen.');
                }
                if($r['annual_fee']!==''&&!preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:[.,][0-9]{1,2})?\z/D',$r['annual_fee']))throw new DomainException('Jahresbeitrag als Eurobetrag ohne Tausendertrennzeichen oder Währungszeichen eintragen.');
            }catch(DomainException $failure){$refuse($index,$failure->getMessage());}
            unset($r);
        }unset($row);
        foreach($groups as$groupKey=>$indices){$key=substr($groupKey,4);
            try{
                if(str_starts_with($key,'ID:')){
                    $id=substr($key,3);$family=$families[$id]??null;
                    if($family===null)throw new DomainException('Die ausdrücklich angegebene bestehende Familie ist im eigenen Verein nicht verfügbar.');
                    $resolved=['mode'=>'existing','id'=>$id,'name'=>$family['name'],'version'=>(int)$family['version'],'payer'=>$family['payer_contact_id'],'payer_name'=>$family['payer_name']];
                    foreach($indices as$index){$row=$rows[$index];$r=$row['record'];if($r['family_name']!==''&&$r['family_name']!==$family['name'])throw new DomainException('Familienname weicht von der bestehenden Familie ab. Bestehende Angaben bleiben erhalten.');if($r['_payer']&&$row['target']!==$family['payer_contact_id'])throw new DomainException('Beitragszahler der bestehenden Familie bleibt unverändert. Zuordnung im Profil prüfen.');if($row['state']==='existing'&&($people[$row['target']]??'')!==$id)throw new DomainException('Bestehendes Mitglied: Familienänderung zuerst im Profil prüfen.');if($row['target']!==''&&isset($people[$row['target']])&&$people[$row['target']]!==$id)throw new DomainException('Vorhandene Familienzuordnung weicht ab. Keine Person wird automatisch umgeordnet.');}
                }else{
                    $payers=array_values(array_filter($indices,static fn($index)=>$rows[$index]['record']['_payer']??false));
                    $names=array_values(array_unique(array_filter(array_map(static fn($index)=>$rows[$index]['record']['family_name'],$indices),static fn($value)=>$value!=='')));
                    if(count($payers)!==1||count($names)!==1)throw new DomainException('Jede neue Familie benötigt genau einen mit ja markierten Beitragszahler und einen einheitlichen Familiennamen.');
                    foreach($indices as$index)if($rows[$index]['state']==='existing')throw new DomainException('Bestehendes Mitglied: Familienänderung zuerst im Profil prüfen.');
                    $payer=$rows[$payers[0]];
                    if($payer['state']==='existing'||($payer['target']!==''&&isset($people[$payer['target']])))throw new DomainException('Für einen bestehenden Beitragszahler die Familie zuerst im Profil einrichten und ihre ID-Kennung verwenden.');
                    foreach($indices as$index)if($rows[$index]['target']!==''&&isset($people[$rows[$index]['target']]))throw new DomainException('Vorhandene Familienzuordnung bleibt erhalten. Bestehende Familie ausdrücklich über ihre ID-Kennung auswählen.');
                    $resolved=['mode'=>'new','key'=>$key,'name'=>$names[0],'payer_line'=>$payer['line'],'payer_name'=>$payer['record']['name']];
                }
                foreach($indices as$index)$rows[$index]['record']['_family']=$resolved;
            }catch(DomainException $failure){foreach($indices as$index)$refuse($index,$failure->getMessage());}
        }
        foreach($rows as$index=>&$row){$r=&$row['record'];
            try{
                $family=$r['_family'];$payer=$family===null?null:($family['mode']==='new'?$row['line']===$family['payer_line']:$row['target']===$family['payer']);
                if($family===null&&$row['target']!==''&&isset($people[$row['target']])){$family=$families[$people[$row['target']]];$payer=$family['payer_contact_id']===$row['target'];}
                if($family!==null||$r['annual_fee']!==''){
                    if($rateFailure!==''&&$row['state']==='existing'&&$r['annual_fee']==='')continue;
                    if($rateFailure!=='')throw new DomainException($rateFailure);
                    $expected=$family!==null&&!$payer?0:($rates[$family!==null?'family':'single']['amount_cents']??null);
                    if($expected===null)throw new DomainException('Beitragstarife zuerst im Verein einrichten.');
                    $r['_annual_cents']=$expected;
                    if($r['annual_fee']!==''&&Finance::cents($r['annual_fee'])!==$expected)throw new DomainException('Jahresbeitrag passt nicht zum aktuellen Einzel- oder Familientarif. Tarif prüfen; Angehörige sind im Familienbeitrag enthalten.');
                }
            }catch(DomainException $failure){$refuse($index,$failure->getMessage());}unset($r);
        }unset($row);
        return['rows'=>$rows,'source'=>['families'=>$families,'people'=>$people,'rates'=>$rates]];
    }
    public function apply(#[\SensitiveParameter] array $rows,array $ids,?MemberImportBankPermit $permit,string $hash):void
    {
        $registry=new Registry($this->db,$this->actor);$created=[];
        foreach($rows as$row){if(!isset($ids[$row['line']]))continue;$id=$ids[$row['line']];$family=$row['record']['_family']??null;if($family===null)continue;
            if($family['mode']==='new'){
                $key=$family['key'];
                if(!isset($created[$key])){$payer=$ids[$family['payer_line']]??null;if($payer===null)throw new DomainException('Ursprünglicher Familien-Beitragszahler fehlt.',409);$registry->assignFamily($payer,'','new',$family['name']);$created[$key]=$registry->family($payer)['id'];}
                $target=$created[$key];
            }else{$target=$family['id'];}
            $current=$registry->family($id);if(($current['id']??'')!==$target)$registry->assignFamily($id,$current['id']??'',$target);
        }
        $vault=null;
        foreach($rows as$row){if(!isset($ids[$row['line']])||($row['record']['_bank']??null)===null)continue;if($permit===null)throw new DomainException('Bankdatenübernahme zuerst mit Passwort und gegebenenfalls Sicherheitscode bestätigen.',403);($vault??=MemberBankVault::fromEnvironment($this->db,$this->actor,ProductionRuntime::enabled()))->importNew($ids[$row['line']],$row['record']['_bank'],$permit,$hash);}
        if($permit!==null)$permit->assert($this->db,$this->actor,$hash);
    }
}
