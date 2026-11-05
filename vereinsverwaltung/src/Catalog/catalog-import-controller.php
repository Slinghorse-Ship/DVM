<?php declare(strict_types=1);
use Kulturhus\Verein\CatalogImports;
require_once __DIR__.'/CatalogImports.php';
function catalogImportAction(PDO $db,string $actor,string $action,array $post,array $uploads):string
{
    $service=new CatalogImports($db,$actor);
    if(!$service->canManage())throw new DomainException('Kontenplan-Aktualisierung nur durch die zentrale Administration.');
    $integer=static function(string $key)use($post):int{if(!is_string($post[$key]??null)||!preg_match('/^[0-9]{1,6}$/D',$post[$key]))throw new DomainException('Jahr oder Prüfstand ungültig.');return (int)$post[$key];};
    if($action==='catalog-import-start')return $service->start(input($post,'request_id'),$integer('from_year'),$integer('year'));
    $operation=match($action){'catalog-import-fetch'=>'fetch','catalog-import-compare'=>'compare','catalog-import-approve'=>'approve',default=>throw new DomainException('Unbekannter Prüfschritt.')};
    $review=null;
    if($operation==='approve'){
        if(input($post,'review_confirmed')!=='1')throw new DomainException('Die fachliche Prüfung bestätigen.');
        $upload=$uploads['catalog_review']??null;
        if(!is_array($upload)||($upload['error']??null)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])||filesize($upload['tmp_name'])>1048576)throw new DomainException('Prüfdatei als JSON auswählen, höchstens 1 MB.');
        $review=(string)file_get_contents($upload['tmp_name']);
    }
    $id=input($post,'catalog_id');$service->advance($id,$integer('version'),$operation,$operation==='compare'?$integer('appendix_pages'):null,$review);return $id;
}
