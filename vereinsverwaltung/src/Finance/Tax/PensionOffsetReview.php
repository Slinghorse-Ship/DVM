<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalPensionOffsets.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';

/** Original document revisions and explicit selectors; never writes financial entries. */
final class PensionOffsetReview
{
    private static function signed(string $value):int
    {
        $v=trim($value);$negative=str_starts_with($v,'-');return ($negative?-1:1)*Finance::cents($negative?substr($v,1):$v);
    }
    public function __construct(private PDO $db,private string $actor){}
    public function evidence(array $source,bool $lock=false):void
    {
        $e=new AssetValuationEvidence($this->db,$this->actor);
        $e->check($source['book_document'],$lock);
        if($source['tax_document']!==null)$e->check($source['tax_document'],$lock);
    }
    public function build(array $report,array $input,bool $fiscal=true):array
    {
        if(array_diff(array_keys($input),['pairs','tax_positions','book_document_id','tax_document_id','book_reviewed','tax_reviewed']))throw new DomainException('Pensionsnachweis enthält unbekannte Angaben.');
        foreach(['book_document_id','tax_document_id','book_reviewed','tax_reviewed'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Dokument und ausdrückliche Bestätigung prüfen.');
        $pairs=$input['pairs']??null;$tax=$input['tax_positions']??[];
        if(!is_array($pairs)||count($pairs)>20||!is_array($tax)||count($tax)>200)throw new DomainException('Einzelne Verrechnungsvereinbarungen vollständig eingeben.');
        $canonical=[];
        foreach($pairs as $pair){
            if(!is_array($pair)||array_diff(array_keys($pair),['asset','provision','movements','movement_choices','obligation_kind','exclusive_bank_deposit','creditor_protected','sole_purpose','netting_reviewed']))throw new DomainException('Einzelne Verrechnungsvereinbarung prüfen.');
            $movements=$pair['movements']??[];
            if(array_key_exists('movement_choices',$pair)){
                $choices=$pair['movement_choices'];
                if(array_key_exists('movements',$pair)||!is_array($choices)||count($choices)>200)throw new DomainException('Erträge und Aufwendungen dieser Vereinbarung mit den angebotenen Konten auswählen.');
                $movements=[];
                foreach($choices as$key=>$selected){
                    if(!is_string($key)||!is_string($selected)||!in_array($selected,['0','1'],true)||!in_array($report['calculation']['accounts'][$key]['skr_code']??null,['71440','71450','73640','73650'],true))throw new DomainException('Erträge und Aufwendungen dieser Vereinbarung mit den angebotenen Konten auswählen.');
                    if($selected==='1')$movements[]=$key;
                }
            }
            if(!is_array($movements)||!array_is_list($movements)||count($movements)>200||array_filter($movements,static fn($k)=>!is_string($k)))throw new DomainException('Erträge und Aufwendungen der einzelnen Vereinbarung auswählen.');
            foreach(['exclusive_bank_deposit','creditor_protected','sole_purpose','netting_reviewed']as$key)if(array_key_exists($key,$pair)&&!is_string($pair[$key]))throw new DomainException('Dokument und ausdrückliche Bestätigung prüfen.');
            if(($pair['asset']??'')===''&&($pair['provision']??'')===''){if($movements!==[]||!empty($pair['obligation_kind'])||!empty($pair['exclusive_bank_deposit'])||!empty($pair['creditor_protected'])||!empty($pair['sole_purpose'])||!empty($pair['netting_reviewed']))throw new DomainException('Begonnene Vereinbarung vollständig auswählen.');continue;}
            foreach(['asset','provision','obligation_kind'] as $key)if(!is_string($pair[$key]??null))throw new DomainException('Vermögen, Verpflichtung und Zusageart auswählen.');
            $canonical[]=['asset'=>$pair['asset'],'provision'=>$pair['provision'],'movements'=>array_values(array_filter($movements,static fn($k)=>$k!=='')),'obligation_kind'=>$pair['obligation_kind'],
                'exclusive_bank_deposit'=>($pair['exclusive_bank_deposit']??'')==='1','creditor_protected'=>($pair['creditor_protected']??'')==='1','sole_purpose'=>($pair['sole_purpose']??'')==='1','netting_reviewed'=>($pair['netting_reviewed']??'')==='1'];
        }
        $positions=[];
        foreach($tax as $key=>$p){
            if(!is_string($key)||!is_array($p)||array_keys($p)!==['account','opening','closing'])throw new DomainException('Unabhängige Steuerwerte vollständig eingeben.');
            foreach(['account','opening','closing'] as $k)if(!is_string($p[$k]))throw new DomainException('Steuerkonto und unabhängige Beträge eingeben.');
            if($p['account']===''&&$p['opening']===''&&$p['closing']==='')continue;
            $positions[$key]=['account'=>$p['account'],'opening_cents'=>self::signed($p['opening']),'closing_cents'=>self::signed($p['closing'])];
        }
        if(!$fiscal&&($positions||($input['tax_document_id']??'')!==''||($input['tax_reviewed']??'')==='1'))throw new DomainException('Handelsrechtlichen Nachweis und steuerliche Bestätigung getrennt erfassen.');
        $e=new AssetValuationEvidence($this->db,$this->actor);
        $source=['schema'=>1,'year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'pairs'=>$canonical,'tax_positions'=>$positions,
            'book_document'=>$e->reference((string)($input['book_document_id']??''),true),'tax_document'=>$fiscal?$e->reference((string)($input['tax_document_id']??''),true):null,
            'book_reviewed'=>($input['book_reviewed']??'')==='1','tax_reviewed'=>$fiscal&&($input['tax_reviewed']??'')==='1'];
        OriginalPensionOffsets::bind($report,$source,$report['calculation'],$fiscal);
        return $source;
    }
}
