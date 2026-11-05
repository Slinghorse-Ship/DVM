<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;use DomainException;use Throwable;
use Kulturhus\Verein\{Finance,Documents};
/** Read and compare an existing expense. Only the existing native command saves its link. */
final class InvoiceAssignment
{
    public static function requested(array $query,array $post):bool
    {
        if(($post['action']??null)==='invoice-inbox-assign')return true;
        foreach(['assign_mode','assign_q','assign_page','assign_expense','assign_read']as$key)if(array_key_exists($key,$query))return true;
        return false;
    }
    public static function originalRoute(array $query):array
    {
        return ['view'=>'finance','tab'=>'inbox']+array_intersect_key($query,array_flip(['inbox_doc','status','inbox_q','inbox_year','inbox_page']));
    }
    private static function reference(mixed $value):?array
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return null;
        $parts=explode(':',$value);
        if(count($parts)!==2||$parts[0]===''||strlen($parts[0])>128||preg_match('/[\x00-\x1f\x7f]/',$parts[0])||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$parts[1])||(int)$parts[1]>2147483647)return null;
        return $parts;
    }
    public static function prepare(PDO $db,string $actor,array $query,array $post):array
    {
        $submitted=($post['action']??null)==='invoice-inbox-assign';
        $out=['state'=>'invalid','status'=>422,'message'=>'Ursprüngliche Ausgabenauswahl vollständig prüfen.','raw'=>[],'document'=>null,'expense'=>null,'reference'=>null,'document_version'=>null,'query'=>'','page'=>'1','catalogue'=>null,'original'=>self::originalRoute($query),'list'=>null,'list_keeps_query'=>false,'refresh'=>null,'submitted'=>$submitted];
        foreach(['assign_mode','assign_q','assign_page','assign_expense']as$key)if(array_key_exists($key,$query))$out['raw'][$key]=$query[$key];
        if($submitted)foreach(['doc','document_version','expense']as$key)if(array_key_exists($key,$post))$out['raw'][$key]=$post[$key];
        $own=!$db->inTransaction();
        try{
            if($own){$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();}
            $source=InvoiceInboxSource::prepare($db,$actor,$query,$submitted?$post:[],$submitted?'POST':'GET');
            if($source['state']!=='selected'){$out['state']='unavailable';$out['status']=$source['status']===200?422:$source['status'];$out['message']='Die ursprüngliche Rechnung ist für diese Ausgabenzuordnung nicht verfügbar.';return$out;}
            $doc=$source['selected'];$out['document']=$doc;$out['original']=self::originalRoute($query);$base=$out['original']+['assign_mode'=>'existing'];
            if((array_key_exists('assign_mode',$query)&&$query['assign_mode']!=='existing')||(!$submitted&&!array_key_exists('assign_mode',$query)))return$out;
            $finance=new Finance($db,$actor);$documents=new Documents($db,$actor);
            if(!$finance->canWrite()||!$documents->canEdit($doc['id'])){$out['state']='blocked';$out['status']=403;$out['message']='Für diese Zuordnung fehlen die aktuellen Bearbeitungsrechte.';return$out;}
            if($doc['state']!=='active'||$doc['entry_links']!==[]){$out['state']='conflict';$out['status']=409;$out['message']='Die Originalrechnung ist inzwischen archiviert oder bereits einem Beleg zugeordnet. Bitte den aktuellen Stand derselben Rechnung lesen.';return$out;}
            $out['list']=$base;
            $term=$query['assign_q']??'';$page=$query['assign_page']??'1';
            if(!is_string($term)||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>150||preg_match('/[\x00-\x1f\x7f]/',$term)||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)return$out;
            $out['query']=$term;$out['page']=$page;$out['list']=$base+['assign_q'=>$term];$out['list_keeps_query']=true;
            $ref=$submitted?($post['expense']??null):($query['assign_expense']??null);
            if($submitted||array_key_exists('assign_expense',$query)){
                $parts=self::reference($ref);if($parts===null)return$out;
                $version=$submitted?($post['document_version']??null):(string)$doc['version'];
                $out['reference']=$ref;$out['document_version']=$version;
                if(!is_string($version)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version)||(int)$version>2147483647)return$out;
                try{$expense=$finance->entry($parts[0]);}catch(DomainException){$out['state']='unavailable';$out['status']=404;$out['message']='Die ursprünglich ausgewählte Ausgabe ist derzeit nicht verfügbar.';return$out;}
                if($expense['kind']!=='expense'||in_array($expense['payment_state'],['cancelled','reversed'],true)){$out['state']='unavailable';$out['status']=409;$out['message']='Die ursprünglich ausgewählte Ausgabe ist nicht mehr für diese Zuordnung verfügbar.';return$out;}
                $out['expense']=$expense;$out['refresh']=$base+['assign_q'=>$term,'assign_expense'=>$expense['id'].':'.$expense['version']];
                if($version!==(string)$doc['version']||$parts[1]!==(string)$expense['version']){$out['state']='conflict';$out['status']=409;$out['message']='Rechnung oder ausgewählte Ausgabe wurde inzwischen geändert. Die ursprüngliche Auswahl bleibt erhalten; beide Originale vor einer neuen Bestätigung gesondert lesen.';return$out;}
                $out['state']='review';$out['status']=200;$out['message']='';return$out;
            }
            $catalogue=$finance->entriesPage(['kind'=>'expense','q'=>$term],$page,'document_date','desc',6);
            if($catalogue['page']!==(int)$page){$out['state']='unavailable';$out['status']=422;$out['message']='Die ursprünglich angeforderte Ausgabenseite ist nicht verfügbar. Die Liste ausdrücklich neu öffnen.';return$out;}
            $out['catalogue']=$catalogue;$out['state']='browse';$out['status']=200;$out['message']='';return$out;
        }catch(Throwable){$out['state']='unavailable';$out['status']=503;$out['message']='Die ursprüngliche Ausgabenauswahl konnte gerade nicht gelesen werden. Bitte erneut prüfen.';return$out;}
        finally{if($own&&$db->inTransaction())$db->rollBack();}
    }
}
