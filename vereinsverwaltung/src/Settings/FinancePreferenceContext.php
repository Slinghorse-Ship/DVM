<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,TaxCatalog};
/** Actual current chosen account metadata; hiding a code never changes a financial original. */
final class FinancePreferenceContext
{
    public static function read(PDO $db,string $actor,array $data):array
    {
        if(!$db->inTransaction())throw new DomainException('Finanz-Vorauswahl benötigt eine aktuelle Prüfung.',503);$finance=new Finance($db,$actor);$out=[];
        foreach(TaxCatalog::YEARS as$year){$catalog=$finance->taxAccounts($year);$rows=[];foreach($data['accounts'][$year]['codes']as$code){if(!isset($catalog[$code]))throw new DomainException('Steuerkonto '.$code.' ist für '.$year.' nicht im Kontenplan dieses Vereins vorhanden.',422);$rows[]=$catalog[$code];}$out[$year]=['mode'=>$data['accounts'][$year]['mode'],'selected'=>$rows];}
        return$out;
    }
    public static function page(PDO $db,string $actor,mixed $year,mixed $query='',mixed $page='1',?array $selected=null):array
    {
        if(!is_string($year)||!in_array($year,array_map('strval',TaxCatalog::YEARS),true))throw new DomainException('Vollständiges verfügbares Kontenjahr auswählen.',422);
        if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query))throw new DomainException('Kontensuche mit höchstens 150 Zeichen eingeben.',422);
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige verfügbare Kontenseite auswählen.',422);
        $rows=[];foreach((new Finance($db,$actor))->taxAccounts((int)$year)as$row){if($selected!==null&&!in_array((string)$row['code'],$selected,true))continue;if($selected===null&&!$row['active'])continue;if($query!==''&&!str_contains(mb_strtolower($row['code'].' '.$row['name'].' '.$row['category'],'UTF-8'),mb_strtolower($query,'UTF-8')))continue;$rows[]=$row;}
        $total=count($rows);$pages=max(1,(int)ceil($total/6));if((int)$page>$pages)throw new DomainException('Vollständige verfügbare Kontenseite auswählen.',422);return['rows'=>array_slice($rows,((int)$page-1)*6,6),'total'=>$total,'pages'=>$pages,'page'=>(int)$page,'page_input'=>(string)$page,'query'=>$query,'year'=>$year];
    }
}
