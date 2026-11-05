<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Original ordinary XML-mapping form; the financial service owns approval. */
final class BalanceMappingForm
{
    public const ACTION='balance-mapping-review';
    public const ACTIONS=[self::ACTION];
    public const COMPLETE='annual_mapping_form_complete';
    public static function route(array $post):array
    {
        return ['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>is_string($post['balance_year']??null)?$post['balance_year']:''];
    }
    public static function metadata(array $post):array
    {
        foreach(['action','balance_year','version','review_version','source_sha256']as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Zuordnungsprüfung vollständig auswählen.',422);
        if($post['action']!==self::ACTION||!preg_match('/\A[0-9]{4}\z/D',$post['balance_year'])||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$post['version'])||(int)$post['version']>4294967295||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$post['review_version'])||(int)$post['review_version']>4294967295||!preg_match('/\A[a-f0-9]{64}\z/D',$post['source_sha256']))throw new DomainException('Ursprüngliche Zuordnungsprüfung vollständig auswählen.',422);
        BalanceCalculation::year((int)$post['balance_year']);return['year'=>(int)$post['balance_year'],'version'=>(int)$post['version'],'review_version'=>(int)$post['review_version'],'source_sha256'=>$post['source_sha256']];
    }
    public static function read(array $post):array
    {
        $meta=self::metadata($post);$mapping=$post['mapping']??null;
        if(!array_key_exists('mapping',$post)&&($post['mapping_empty']??null)==='1')$mapping=[];
        if(($post[self::COMPLETE]??null)!=='1'||!is_array($mapping)||count($mapping)>500||(array_key_exists('reviewed',$post)&&$post['reviewed']!=='1')||(array_key_exists('mapping_empty',$post)&&($post['mapping_empty']!=='1'||array_key_exists('mapping',$post))))throw new DomainException('Ursprüngliches Zuordnungsformular vollständig prüfen.',422);
        foreach($mapping as$account=>$target)if(!is_string($account)||!is_string($target))throw new DomainException('Ursprüngliche Kontenauswahl vollständig prüfen.',422);
        return$meta+['mapping'=>$mapping,'reviewed'=>($post['reviewed']??null)==='1'];
    }
    public static function status(DomainException $failure):int
    {
        if(in_array($failure->getCode(),[403,404,409,422,503],true))return$failure->getCode();
        if($failure->getMessage()==='Konten und tatsächliche Bedeutung ausdrücklich prüfen.'&&!BalanceLedger::enabled())return 403;
        if(in_array($failure->getMessage(),['Finanzberechtigung erforderlich.','Zugang nicht aktiv.','Für diesen Verein ist keine Bilanz eingestellt.'],true))return 403;
        if(in_array($failure->getMessage(),['Offenen Buchstand neu laden.','Buchstand oder Zuordnungen inzwischen geändert. Erneut prüfen.','Vereinszuordnung inzwischen geändert.'],true))return 409;
        return 422;
    }
}
