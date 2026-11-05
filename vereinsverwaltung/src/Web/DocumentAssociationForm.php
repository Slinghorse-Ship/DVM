<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Verein\{Documents,LiveUploads};

/** Browsing the original document form never enters a document or financial writer. */
final class DocumentAssociationForm
{
    public static function route(array $query,array $post):void
    {
        if(($query['view']??null)!=='documents'||!in_array($post['action']??null,['document-upload-many','document-update'],true)||array_diff(array_keys($query),['view','doc','new_document','scope','q','folder','kind','state','unassigned','source_type','source_id','recursive','page','size','doc_mode'])!==[])throw new \DomainException('Vorgangssuche im ursprünglichen Dokumentformular öffnen.',422);
        $allowed=['action','csrf','doc','version','reason','request_key','title','kind','document_date','folder','scope','notes','state','links','document_browse','document_source_query','document_source_type','document_source_page','live_uploads','document_titles','document_dates','document_groups','document_group_ids','document_group_links','document_group_folders','document_group_scopes','document_confirm'];
        if(array_diff(array_keys($post),$allowed)!==[]||(!is_string($post['document_browse']??null)||(!in_array($post['document_browse'],['search','previous','next','page'],true)&&!preg_match('/\Aremove:[a-f0-9]{64}\z/D',$post['document_browse']))))throw new \DomainException('Dokumentzuordnungen vollständig prüfen.',422);
        if($post['action']==='document-update'&&(!is_string($post['doc']??null)||$post['doc']===''||($query['doc']??null)!==$post['doc']))throw new \DomainException('Vorgangssuche im ursprünglichen Dokumentformular öffnen.',422);
        if($post['action']==='document-upload-many'&&isset($query['doc']))throw new \DomainException('Vorgangssuche im ursprünglichen Dokumentformular öffnen.',422);
    }
    public static function selected(array $post):array
    {
        $selected=$post['links']??[];
        if(!is_array($selected)||!array_is_list($selected)||count($selected)>30)throw new \DomainException('Höchstens 30 Zuordnungen pro Dokument.');
        $groups=$post['document_group_links']??[];
        if(!is_array($groups)||count($groups)>10)throw new \DomainException('Dokumentzuordnungen vollständig prüfen.');
        foreach($groups as$id=>$links){if(!is_string($id)||!preg_match('/\Ag[0-9]\z/D',$id)||!is_array($links)||!array_is_list($links)||count($links)>30)throw new \DomainException('Höchstens 30 Zuordnungen pro Dokument.');$selected=[...$selected,...$links];}
        foreach($selected as$value)if(!is_string($value))throw new \DomainException('Dokumentzuordnungen vollständig prüfen.');
        return array_values(array_unique($selected));
    }
    public static function read(\PDO $db,string $actor,array $query,array &$post,array $files):void
    {
        self::route($query,$post);$documents=new Documents($db,$actor);$doc=$post['action']==='document-update'?$post['doc']:'';
        if(!$documents->canWrite()||($doc!==''&&!$documents->canEdit($doc)))throw new \DomainException('Keine Berechtigung für diese Dokumentzuordnung.',403);
        self::retainUploads($db,$actor,$post,$files);
        $page=$post['document_source_page']??'1';
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new \DomainException('Vorgangssuche und Seite prüfen.',422);
        $page=match($post['document_browse']){'search'=>1,'previous'=>max(1,(int)$page-1),'next'=>min(999999999,(int)$page+1),default=>(int)$page};
        $result=$documents->sourceChoicePage(['query'=>$post['document_source_query']??'','type'=>$post['document_source_type']??'','page'=>(string)$page],self::selected($post),$doc);
        $post['document_source_page']=(string)$result['page'];
    }
    public static function retainUploads(\PDO $db,string $actor,array &$post,array $files):void
    {
        // Native multipart files become the same private, session-bound drafts used by enhanced uploads.
        $tokens=$post['live_uploads']??[];
        if(!is_array($tokens)||array_diff(array_keys($tokens),['document_files'])!==[]||!is_array($tokens['document_files']??[])||!array_is_list($tokens['document_files']??[])||count($tokens['document_files']??[])>10)throw new \DomainException('Uploadzuordnung ungültig.');
        foreach($tokens['document_files']??[]as$token){if(!is_string($token))throw new \DomainException('Uploadzuordnung ungültig.');LiveUploads::description($actor,$token,'document-upload-many','document_files');}
        if($files!==[]&&$post['action']!=='document-upload-many')throw new \DomainException('Uploadzuordnung ungültig.');
        $remove=$post['document_browse']??'';
        if(is_string($remove)&&str_starts_with($remove,'remove:')){
            $token=substr($remove,7);if(!in_array($token,$tokens['document_files']??[],true))throw new \DomainException('Uploadzuordnung ungültig.');
            LiveUploads::remove($actor,$token);$tokens['document_files']=array_values(array_diff($tokens['document_files'],[$token]));$post['live_uploads']=$tokens;
            foreach(['document_titles','document_dates','document_groups']as$field)if(is_array($post[$field]??null))unset($post[$field][$token]);
        }
        $incoming=$files['document_files']??null;
        if($incoming!==null){
            if(!is_array($incoming)||!is_array($incoming['error']??null)||!array_is_list($incoming['error'])||count($incoming['error'])>10)throw new \DomainException('Eine bis zehn Dateien auswählen.');
            foreach($incoming['error']as$i=>$error){if($error===UPLOAD_ERR_NO_FILE)continue;if(count($tokens['document_files']??[])>=10)throw new \DomainException('Eine bis zehn Dateien auswählen.');$file=[];foreach(['name','type','tmp_name','error','size']as$key)$file[$key]=$incoming[$key][$i]??null;LiveUploads::permit($db,$actor,'document-upload-many',$post);$staged=LiveUploads::stage($actor,'document-upload-many','document_files',$file,bin2hex(random_bytes(16)));$tokens['document_files'][]=$staged['token'];$post['live_uploads']=$tokens;}
        }
    }
}
