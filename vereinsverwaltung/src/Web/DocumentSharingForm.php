<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Verein\{BoardAccess,Documents,Pagination,PortalDocuments,Registry};

/** Recipient navigation retains the independently reviewed file and grant versions. */
final class DocumentSharingForm
{
    public static function target(array $query,array $post):void
    {
        if(($query['view']??null)!=='documents'||!is_string($query['doc']??null)||$query['doc']===''||($post['action']??null)!=='portal-share'||($post['doc']??null)!==$query['doc']||array_diff(array_keys($query),['view','doc','scope','q','folder','kind','state','unassigned','source_type','source_id','recursive','page','size','doc_mode'])!==[])throw new \DomainException('Persönliche Freigabe im ursprünglichen Dokument öffnen.',422);
        if(array_diff(array_keys($post),['action','csrf','doc','version','revision','contact','enabled','share_read','contact_query','contact_page','share_query','share_page'])!==[])throw new \DomainException('Persönliche Freigabe vollständig prüfen.',422);
        foreach($post as$value)if(!is_string($value))throw new \DomainException('Persönliche Freigabe vollständig prüfen.',422);
    }
    public static function route(array $query,array $post):void
    {
        self::target($query,$post);
        $operation=$post['share_read']??null;
        if(!is_string($operation)||(!in_array($operation,['contact-search','contact-previous','contact-next','contact-page','shares-search','shares-previous','shares-next','shares-page'],true)&&!preg_match('/\Aselect:[a-zA-Z0-9_-]{1,64}\z/D',$operation)))throw new \DomainException('Persönliche Freigabe vollständig prüfen.',422);
    }
    public static function state(\PDO $db,string $actor,string $id,array $post,?array $displayedDocument=null):array
    {
        $who=BoardAccess::require($db,$actor);$documents=new Documents($db,$actor);$doc=$documents->document($id,true);
        if($doc['admission_only']||$who['members_access']==='none')throw new \DomainException('Keine ausreichende Berechtigung für diese Dokumentfreigabe.',403);
        $displayed=$displayedDocument??$doc;
        if(($displayed['id']??null)!==$id||($displayed['club_id']??null)!==$doc['club_id']||(!is_int($displayed['version']??null)&&!is_string($displayed['version']??null))||(!is_int($displayed['revision']??null)&&!is_string($displayed['revision']??null)))throw new \DomainException('Persönliche Freigabe im ursprünglichen Dokument öffnen.',422);
        $current=(new PortalDocuments($db,$actor))->sharingState($id);$draft=($post['action']??null)==='portal-share'&&($post['doc']??null)===$id;$errors=[];
        $value=static function(string $key,string $default='')use($post,$draft,&$errors):string{if(!$draft||!array_key_exists($key,$post))return $default;if(!is_string($post[$key])){$errors[]='Persönliche Freigabe vollständig prüfen.';return '';}return $post[$key];};
        $version=$value('version',$draft?'':(string)$displayed['version']);$revision=$value('revision',$draft?'':(string)$displayed['revision']);$contact=$value('contact');$enabled=$value('enabled',$draft?'':($doc['state']==='active'?'1':'0'));
        $query=$value('contact_query');$number=$value('contact_page','1');$shareQuery=$value('share_query');$shareNumber=$value('share_page','1');$registry=new Registry($db,$actor);
        $empty=['rows'=>[],'selected'=>null,'query'=>$query,'page'=>1,'pages'=>1,'total'=>0];
        try{$choices=$registry->contactChoicePage(['contact_query'=>$query,'contact_page'=>$number]);}catch(\DomainException $error){$errors[]=$error->getMessage();$choices=$empty;}
        $selected=null;
        if($contact!==''){
            try{$selected=$registry->contactChoicePage(['contact_id'=>$contact])['selected'];}
            catch(\DomainException){foreach($current['shares']as$grant)if($grant['contact_id']===$contact){$selected=['id'=>$contact,'name'=>$grant['name'],'archived_at'=>$grant['archived_at']];break;}if($selected===null)$selected=['id'=>$contact,'name'=>$contact,'unavailable'=>true];}
        }
        try{
            if(preg_match('//u',$shareQuery)!==1||mb_strlen($shareQuery,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$shareQuery)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$shareNumber))throw new \DomainException('Freigabesuche und Seite prüfen.');
            $grants=array_values(array_filter($current['shares'],static fn($row):bool=>trim($shareQuery)===''||mb_stripos($row['name'],trim($shareQuery),0,'UTF-8')!==false));$grants=Pagination::slice($grants,(int)$shareNumber,6);
        }catch(\DomainException $error){$errors[]=$error->getMessage();$grants=['rows'=>[],'page'=>1,'pages'=>1,'total'=>0,'size'=>6];}
        $reviewed=filter_var($version,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false&&filter_var($revision,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false;
        if(!$reviewed)$errors[]='Geprüfte Dateifassung und Freigabestand fehlen. Dokument neu öffnen und prüfen.';
        if(!in_array($enabled,['0','1'],true))$errors[]='Freigabeaktion auswählen.';
        $stale=$reviewed&&((int)$version!==$current['version']||(int)$revision!==(int)$doc['revision']);
        return ['doc'=>$doc,'version'=>$version,'revision'=>$revision,'contact'=>$contact,'enabled'=>$enabled,'query'=>$query,'page'=>$number,'share_query'=>$shareQuery,'share_page'=>$shareNumber,'choices'=>$choices,'selected'=>$selected,'grants'=>$grants,'all_grants'=>$current['shares'],'errors'=>array_values(array_unique($errors)),'stale'=>$stale,'reviewed'=>$reviewed];
    }
    public static function read(\PDO $db,string $actor,array $query,array &$post):void
    {
        self::route($query,$post);$operation=$post['share_read'];
        $state=self::state($db,$actor,$query['doc'],$post);
        if(str_starts_with($operation,'select:')){
            $id=substr($operation,7);if(!array_filter($state['all_grants'],static fn($row):bool=>$row['contact_id']===$id))throw new \DomainException('Gespeicherte Freigabe nicht mehr verfügbar.',409);$post['contact']=$id;
        }else{
            $contacts=str_starts_with($operation,'contact-');$field=$contacts?'contact_page':'share_page';$page=$post[$field]??'1';
            if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new \DomainException($contacts?'Kontaktsuche und Seite prüfen.':'Freigabesuche und Seite prüfen.',422);
            $post[$field]=(string)match(substr($operation,strpos($operation,'-')+1)){'search'=>1,'previous'=>max(1,(int)$page-1),'next'=>min(999999999,(int)$page+1),default=>(int)$page};
        }
        $state=self::state($db,$actor,$query['doc'],$post);
        if($state['errors'])throw new \DomainException($state['errors'][0],422);
        $post['contact_page']=(string)$state['choices']['page'];$post['share_page']=(string)$state['grants']['page'];
        if($state['stale'])throw new \Kulturhus\Verein\Documents\ShareConflict();
    }
}
