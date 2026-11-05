<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Reuse explicitly stored club data. Never infer annual confirmations or copy into tests. */
final class TaxClubData
{
    public static function stored(PDO $db,string $actor):array
    {
        $a=Permissions::actor($db,$actor);
        if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanz-Leserecht erforderlich.');
        $data=['club'=>[],'tax'=>[]];
        foreach(DocumentCatalog::query($db,"SELECT section,payload FROM club_settings WHERE club_id=? AND section IN ('club','tax')",[$a['club_id']]) as $row)
            $data[$row['section']]=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        return $data;
    }
    public static function number(array $tax):string
    {
        $n=preg_replace('/[\s\/.-]+/u','',(string)($tax['number']??''));
        return preg_match('/^[0-9]{13}$/D',$n)?$n:'';
    }
    public static function profile(array $tax,int $year):array
    {
        $out=[];
        if(in_array($tax['accounting_basis']??'', ['euer','balance'],true))$out['accounting']=$tax['accounting_basis'];
        if(($tax['small_business']??false)===true)$out['vat']='small';
        // A 60a determination alone does not certify the actual management.
        if(in_array($tax['notice_type']??'', ['exemption','corporate'],true)
            &&preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($tax['exemption_date']??''))
            &&$tax['exemption_date']<=$year.'-12-31'&&($tax['receipt_authority_reviewed']??false)===true
            &&($tax['notice_document_id']??'')!=='')$out['nonprofit']='yes';
        return $out;
    }
    public static function form(TaxFormSchema $schema,array $stored,int $year):array
    {
        $club=$stored['club'];$tax=$stored['tax'];$number=self::number($tax);$fields=[];$allowed=$schema->fields();
        $supplier=implode(', ',array_filter([(string)($club['name']??''),(string)($club['street']??''),(string)($club['address2']??''),trim(($club['postcode']??'').' '.($club['city']??''))]));
        $values=['Vorsatz/StNr'=>$number,'Vorsatz/Zeitraum'=>(string)$year,'Vorsatz/AbsName'=>(string)($club['name']??''),
            'Vorsatz/AbsStr'=>(string)($club['street']??''),'Vorsatz/AbsPlz'=>(string)($club['postcode']??''),'Vorsatz/AbsOrt'=>(string)($club['city']??''),
            'Ordnungsbegriff/Steuernummer'=>$number,'Ordnungsbegriff/Bundesfinanzamtsnummer'=>$number!==''?substr($number,0,4):''];
        if(($schema->profile()['family']??'')==='kst'){
            $values['KSt1/Allgemein/E8000101']=(string)($club['name']??'');
            if(($club['registered']??false)===true)$values['KSt1/Steuerpflicht/Rechtsform/E8001105']='520';
        }
        if(($schema->profile()['family']??'')==='euer'&&($schema->profile()['year']??null)===2025){
            foreach(['E6000016'=>'name','E6000023'=>'street','E6000024'=>'postcode','E6000025'=>'city'] as $field=>$setting)
                $values['EUER/Allg/'.$field]=(string)($club[$setting]??'');
        }
        // Exact schema paths only. No fuzzy label matching, tax amounts or guessed exemptions.
        foreach($values as $path=>$value){$key=TaxFormSchema::key($path);if($value!==''&&isset($allowed[$key]))$fields[$key]=$value;}
        $header=['office'=>$number!==''?substr($number,0,4):'','supplier'=>$supplier];
        if(isset($schema->profile()['attachment_version'])&&is_string($club['email']??null)&&strlen($club['email'])<=128&&filter_var($club['email'],FILTER_VALIDATE_EMAIL))$header['feedback_email']=$club['email'];
        return ['fields'=>$schema->normalize($fields),'counts'=>[],'header'=>$header,'attachments'=>[]];
    }
}
