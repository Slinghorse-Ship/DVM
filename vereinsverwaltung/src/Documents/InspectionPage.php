<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use Kulturhus\Verein\DocumentWorkflow\InspectionStore;

final class InspectionPage
{
    public static function handle(InspectionStore $store,array $post): array
    {
        $text=static function(string $key)use($post):string{if(!array_key_exists($key,$post)||!is_string($post[$key]))throw new DomainException('Auftragsformular nicht vollständig übertragen.');return $post[$key];};
        $action=$text('action');$id=$text('wf_id');$versionText=$text('wf_version');
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$versionText))throw new DomainException('Auftragsversion prüfen.');$version=(int)$versionText;
        $state=InspectionStore::state(['query'=>$text('inspection_query'),'status'=>$text('inspection_status'),'scope'=>$text('inspection_scope'),'page'=>$text('inspection_page')]);
        if(in_array($action,['document-workflow-inspection-create','document-workflow-inspection-update'],true)){
            $fields=['title'=>$text('inspection_title'),'instruction'=>$text('inspection_instruction'),'assignee_id'=>$text('inspection_assignee'),'deadline'=>$text('inspection_deadline')];
            if($action==='document-workflow-inspection-create'){
                if($id!==''||$version!==0)throw new DomainException('Neuen Prüfauftrag prüfen.');
                $sourceVersion=$text('inspection_source_revision');if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$sourceVersion))throw new DomainException('Beurteilungsfassung prüfen.');
                $id=$store->create($fields,$text('inspection_request_key'),$text('inspection_source_doc'),(int)$sourceVersion);
            }else $store->update($id,$version,$fields);
        }elseif($action==='document-workflow-inspection-transition'){
            $transition=$text('inspection_transition');$data=['comment'=>$text('inspection_comment')];
            if($transition==='submit'){$data['implementation']=$text('inspection_implementation');$data['completed_on']=$text('inspection_completed_on');}
            $store->transition($id,$version,$transition,$data);
        }else throw new DomainException('Unbekannte Prüfauftragsaktion.');
        return ['view'=>'documents','tab'=>'inspections','inspection'=>$id,'inspection_query'=>$state['query'],'inspection_status'=>$state['status'],'inspection_scope'=>$state['scope'],'inspection_page'=>$state['page']];
    }
}
