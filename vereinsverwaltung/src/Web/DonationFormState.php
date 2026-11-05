<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\DonationForms;

/** Read a source and preserve the complete submitted draft if it disappeared. */
final class DonationFormState
{
    public static function read(PDO $db,string $actor,array $post,array $query):array
    {
        $forms=new DonationForms($db,$actor);
        $ownDraft=in_array($post['action']??null,['donation-preview','donation-save','donation-issue','donation-compare','donation-adopt','donation-search'],true);
        $typed=true;
        $read=static function(string $key,string $default='')use($post,$query,$ownDraft,&$typed):string{
            $source=$ownDraft?$post:$query;
            if(!array_key_exists($key,$source))return $default;
            if(!is_string($source[$key])){$typed=false;return '';}
            return $source[$key];
        };
        $kind=$read('kind','money');$source=$read('source_id');$payment=$read('payment_id');
        $invalidKind=!in_array($kind,['money','goods'],true);
        $displayKind=$invalidKind?'money':$kind;
        $ownTransaction=!$db->inTransaction();
        try{
            if($ownTransaction)$db->beginTransaction();
            // Only this association's unbound defaults are used as an empty form
            // scaffold; they never replace a submitted value or payment source.
            $empty=$forms->defaults($displayKind);
            $current=null;$details=[];$sourceError='';$sourceCode=0;
            if(!$typed||$invalidKind){$sourceError='Geld- oder Sachzuwendung auswählen.';$sourceCode=422;}
            else{
                try{$current=$forms->defaults($kind,$source,$payment);$details=$forms->sourceDetails($kind,$source,$payment);}
                catch(DomainException $error){$sourceError=$error->getMessage();$sourceCode=in_array($error->getCode(),[403,404,409,422,503],true)?$error->getCode():409;}
            }
            $values=$current??$empty;
            if($ownDraft){
                foreach($empty as$key=>$unused){
                    if(!array_key_exists($key,$post)){$values[$key]='';continue;}
                    if(!is_string($post[$key])){$values[$key]='';$typed=false;continue;}
                    $values[$key]=$post[$key];
                }
            }
            $values['kind']=$kind;$values['source_id']=$source;$values['payment_id']=$payment;
            if(!$typed&&$sourceError===''){$sourceError='Ganze ursprüngliche Formularangaben prüfen.';$sourceCode=422;}
            $stale=$ownDraft&&$current!==null&&$values['source_version']!==$current['source_version'];
            if($ownTransaction)$db->rollBack();
            return ['kind'=>$displayKind,'raw_kind'=>$kind,'source_id'=>$source,'payment_id'=>$payment,'values'=>$values,'current'=>$current,'details'=>$details,'error'=>$sourceError,'error_code'=>$sourceCode,'available'=>$current!==null&&$sourceError==='','stale'=>$stale,'own_draft'=>$ownDraft,'comparison_hash'=>hash('sha256',json_encode([$kind,$source,$payment,$current,$details],JSON_THROW_ON_ERROR))];
        }catch(\Throwable $error){if($ownTransaction&&$db->inTransaction())$db->rollBack();throw $error;}
    }
    public static function target(array $post,array $query):void
    {
        if(($query['view']??null)!=='settings'||($query['tab']??null)!=='donation-forms')throw new DomainException('Zuwendungsformular am ursprünglich gewählten Beleg öffnen.',409);
        foreach(['kind'=>'money','source_id'=>'','payment_id'=>'']as$key=>$default){
            if(!is_string($post[$key]??null)||!is_string($query[$key]??$default)||$post[$key]!==($query[$key]??$default))throw new DomainException('Zuwendungsformular am ursprünglich gewählten Beleg öffnen.',409);
        }
    }

    /** Deliberate comparison and adoption only update this submitted draft. */
    public static function handle(PDO $db,string $actor,array &$post,array $query):array
    {
        self::target($post,$query);$state=self::read($db,$actor,$post,$query);
        if(!$state['available']||$state['source_id']==='')throw new DomainException($state['error']?:'Tatsächliche Zuwendung zuerst ausdrücklich auswählen.',409);
        if(($post['action']??null)==='donation-compare')return ['comparison'=>$state,'notice'=>'Aktuelle Zuwendungsquelle gelesen. Die bisherigen Angaben und die Bestätigung bleiben unverändert.'];
        if(($post['action']??null)!=='donation-adopt'||!is_string($post['donation_compare_hash']??null)||!hash_equals($state['comparison_hash'],$post['donation_compare_hash']))throw new DomainException('Zuwendungsquelle inzwischen erneut geändert. Aktuellen Stand nochmals vergleichen.',409);
        $post['source_version']=$state['current']['source_version'];$post['receipt_reviewed']='';
        return ['comparison'=>$state,'adopted'=>true,'notice'=>'Verglichenen Belegstand übernommen. Alle eigenen Angaben bleiben erhalten; die Bestätigung muss neu gesetzt werden.'];
    }

}
