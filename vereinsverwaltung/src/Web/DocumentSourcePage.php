<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Verein\Documents;

final class DocumentSourcePage
{
    public static function applies(array $query,array $post=[]):bool
    {
        return ($query['view']??null)==='documents'&&($query['doc_mode']??null)==='sources'&&($query['doc']??'')===''&&($post['doc']??'')===''
            &&!array_key_exists('new_document',$query)&&!array_key_exists('new_folder',$query)&&!array_key_exists('edit_folder',$query)
            &&($query['state']??'')!=='trash'&&!in_array($post['action']??null,['document-upload','document-upload-many','document-folder'],true)&&!in_array($query['tab']??'',['checks','workflows','activity-reports'],true);
    }
    public static function prepare(Documents $documents,array $query):array
    {
        $filters=[];foreach(['q','scope','source_kind']as$key)$filters[$key]=$query[$key]??'';$number=$query['page']??'1';$state=['filters'=>$filters,'number'=>$number,'page'=>null,'error'=>'','status'=>200];
        try{
            if(!is_string($number)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$number))throw new \DomainException('Vorgangssuche und Seite prüfen.');
            $state['page']=$documents->sourceOverviewPage($filters,(int)$number);
        }catch(\DomainException $error){$state['error']=$error->getMessage();$state['status']=in_array($error->getCode(),[403,404,409,422,503],true)?$error->getCode():422;}
        return$state;
    }
}
