<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\DocumentWorkflow\{WorkflowException,WorkflowStore,WorkflowSignatures};
require_once __DIR__.'/WorkflowFolderTree.php';

/** Read-only preparation before HTML; source originals and rejected write intentions remain separate. */
final class WorkflowBrowseState
{
    public const CONTEXT=['wf_folder','wf_status','wf_search','wf_page','wf_templates_page','wf_versions_page','wf_activities_page','wf_publications_page','wf_requests_page','wf_folder_search','wf_signatures_page','wf_document_signatures_page','wf_signature_members_page','wf_reminders_page','wf_preview_recipients_page'];
    public static function context(array $query):array
    {
        $context=[];foreach(self::CONTEXT as$key)if(is_string($query[$key]??null))$context[$key]=$query[$key];return $context;
    }
    public static function failed(string $message,int $status=422):array
    {
        return ['fatal'=>true,'error'=>$message,'http_status'=>$status,'actor'=>['id'=>'','club_id'=>'','access'=>'none'],'personal'=>false];
    }
    public static function prepare(WorkflowStore $store,array $query,array $post,string $error,string $csrf,?array $templateDraft=null,?array $documentDraft=null,?WorkflowSignatures $signatures=null,?\Kulturhus\Verein\WorkflowReminderMail $reminder=null):array
    {
        $text=static function(array $input,string $key,string $default=''):string{
            if(!array_key_exists($key,$input))return $default;
            if(!is_string($input[$key]))throw new WorkflowException('Workflow-Auswahl prüfen.');
            return $input[$key];
        };
        try{
            $actor=$store->actor();
            $validPost=($error!==''||$templateDraft!==null||$documentDraft!==null||($post['action']??null)==='document-workflow-mail-preview')&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$csrf!==''&&is_string($post['csrf']??null)&&hash_equals($csrf,$post['csrf']);
            if(!$validPost||$actor['access']!=='write'){$templateDraft=null;$documentDraft=null;}
            $action=$validPost?$text($post,'action'):'';
            if($templateDraft!==null&&$action==='document-workflow-template-compose')$action='document-workflow-template-save';
            if($documentDraft!==null&&$action==='document-workflow-document-compose')$action='document-workflow-document-save';
            if($actor['access']==='none'||$text($query,'wf_sign')!==''||$text($query,'wf_own')==='1'||in_array($action,['document-workflow-signature-sign','document-workflow-signature-archive'],true)){
                $personal=$signatures!==null?WorkflowSignatureState::prepare($signatures,$query,$post,$action):null;
                return ['fatal'=>false,'personal'=>true,'actor'=>$actor,'post_action'=>$action,'error'=>$error!==''?$error:($personal['error']??''),'http_status'=>$personal['http_status']??0,'signature'=>$personal];
            }
            if($actor['access']!=='write')$action='';
            $mode=$text($query,'wf_mode','documents');$mode=$mode==='templates'?'templates':'documents';
            $receiptKey=$text($query,'wf_request_check');if($text($query,'wf_requests')==='1'||$receiptKey!=='')$mode='creations';
            $docId=$text($query,'wf_doc');$templateId=$text($query,'wf_template');
            if(in_array($action,['document-workflow-template-save','document-workflow-template-publish'],true)){$mode='templates';$templateId=$text($post,'wf_id');}
            if(in_array($action,['document-workflow-document-save','document-workflow-document-assign','document-workflow-document-transition','document-workflow-attachment-add','document-workflow-attachment-remove','document-workflow-signature-request','document-workflow-mail-preview','document-workflow-mail-confirm'],true))$docId=$text($post,'wf_id');
            $write=$actor['access']==='write';
            $newTemplate=$write&&($text($query,'wf_new_template')==='1'||($action==='document-workflow-template-save'&&$templateId===''));
            $newDocument=$write&&($text($query,'wf_new_document')==='1'||$action==='document-workflow-document-create');
            $documentPost=in_array($action,['document-workflow-document-save','document-workflow-document-assign','document-workflow-document-transition','document-workflow-attachment-add','document-workflow-attachment-remove','document-workflow-signature-request','document-workflow-mail-preview','document-workflow-mail-confirm'],true);
            if($documentPost){$mode='documents';$templateId='';$newTemplate=false;$newDocument=false;}
            $revision=$documentPost?null:self::revision($text($query,'wf_revision'),'Ungültige Dokumentversion.');
            $publication=$documentPost?null:self::revision($text($query,'wf_template_revision'),'Ungültige Vorlagenversion.');
            $document=$docId!==''?$store->document($docId,$revision):null;
            $template=$templateId!==''?($publication!==null?$store->templateVersion($templateId,$publication):$store->template($templateId)):null;
            $state=['fatal'=>false,'personal'=>false,'actor'=>$actor,'post_action'=>$action,'error'=>$error,'http_status'=>0,'mode'=>$mode,'folder'=>$text($query,'wf_folder'),'doc_id'=>$docId,'template_id'=>$templateId,'revision'=>$revision,'template_revision'=>$publication,'new_template'=>$newTemplate,'new_document'=>$newDocument,'document'=>$document,'template'=>$template,'folders'=>$store->folders(),'participants'=>$store->participants(),'template_options'=>[],'pages'=>[],'page_errors'=>[],'raw_pages'=>[],'filters'=>['search'=>$text($query,'wf_search'),'status'=>$text($query,'wf_status')],'content_authors'=>[],'creation_receipt'=>null,'receipt_key'=>$receiptKey,'receipt_kind'=>$text($query,'wf_creation_kind'),'creation_keys'=>[],'template_draft_notice'=>$templateDraft['message']??'','template_draft_focus'=>$templateDraft['focus']??null,'document_draft_notice'=>$documentDraft['message']??'','document_draft_focus'=>$documentDraft['focus']??null,'document_draft_preview'=>$documentDraft['preview']??false];
            if($action==='document-workflow-folder-save'&&$text($post,'wf_id')!==''){$state['folder']=$text($post,'wf_id');$state['mode']=$mode='documents';$state['document']=$document=null;$state['template']=$template=null;$state['new_document']=$newDocument=false;$state['new_template']=$newTemplate=false;}
            $folderTree=new WorkflowFolderTree($state['folders']);$state['folder_tree']=$folderTree;$state['folder_search']=$text($query,'wf_folder_search');$state['folder_search_error']='';$state['folder_rows']=[];
            try{$state['folder_rows']=$folderTree->rows($state['folder_search']);}
            catch(\DomainException $failure){$state['folder_search_error']=$failure->getMessage();$state['http_status']=422;}
            $state['selected_folder']=$state['folder']!==''?$folderTree->get($state['folder']):null;
            $state['folder_edit']=$write&&($text($query,'wf_folder_edit')==='1'||($action==='document-workflow-folder-save'&&$text($post,'wf_id')!==''));
            if($state['folder_edit']&&$state['selected_folder']===null)throw new WorkflowException('Der Ordner wurde nicht gefunden.');
            $state['folder_path']=$state['selected_folder']!==null?$folderTree->path($state['folder']):['rows'=>[],'issue'=>''];
            $kinds=['document'=>'document-workflow-document-create','template'=>'document-workflow-template-save','folder'=>'document-workflow-folder-save'];
            foreach($kinds as$kind=>$operation){$candidate=($action===$operation&&$text($post,'wf_id')==='')?$text($post,'wf_request'):($text($query,'wf_creation_kind')===$kind?$text($query,'wf_creation_key'):'');if($candidate!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$candidate))throw new WorkflowException('Anlegeformular erneut öffnen.');$state['creation_keys'][$kind]=$candidate!==''?$candidate:bin2hex(random_bytes(16));}
            $read=static function(string $name,string $parameter,callable $fetch)use(&$state,$query):void{
                $number=$query[$parameter]??1;$state['raw_pages'][$name]=$number;
                try{$state['pages'][$name]=$fetch($number);}
                catch(WorkflowException $failure){$state['pages'][$name]=null;$state['page_errors'][$name]=$failure->getMessage();if($state['error']==='')$state['error']=$failure->getMessage();$state['http_status']=422;}
            };
            if($mode==='creations'){
                if($receiptKey!==''){$state['creation_receipt']=$store->creationReceipt($receiptKey);if($state['creation_receipt']===null)$state['http_status']=404;}
                $read('creations','wf_requests_page',$store->creationRequests(...));
            }elseif($mode==='templates'){
                if($template!==null)$read('publications','wf_publications_page',static fn(mixed $page):array=>$store->templateVersionPage($templateId,$page));
                elseif(!$newTemplate)$read('templates','wf_templates_page',$store->templatePage(...));
            }elseif($document!==null){
                $read('versions','wf_versions_page',static fn(mixed $page):array=>$store->versionPage($docId,$page));
                $read('activities','wf_activities_page',static fn(mixed $page):array=>$store->activityPage($docId,$page));
                if(!$store->collaborative())$state['content_authors']=$store->contentAuthors($docId);
                if($signatures!==null&&in_array('risk_register',array_column($document['fields'],'type'),true)){
                    $state['signature_names']=array_column($signatures->members(),'name','id');
                    $read('signatures','wf_document_signatures_page',static fn(mixed $page):array=>$signatures->documentPage($docId,(int)$document['revision'],$page));
                    if($write&&$revision===null&&$document['status']==='approved')$read('signature_members','wf_signature_members_page',static fn(mixed $page):array=>$signatures->availableMembersPage($docId,(int)$document['revision'],$page));
                    if($write&&$reminder!==null)$read('reminders','wf_reminders_page',static fn(mixed $page):array=>$reminder->deliveryPage($docId,(int)$document['revision'],$page));
                    try{\Kulturhus\Verein\DocumentWorkflow\WorkflowList::number($query['wf_preview_recipients_page']??1);}
                    catch(WorkflowException $failure){$state['page_errors']['preview_recipients']=$failure->getMessage();$state['http_status']=422;}
                }
            }elseif($newDocument)$state['template_options']=$store->templateOptions();
            else{
                $filters=[];if($state['folder']!=='')$filters['folder_id']=$state['folder'];if($state['filters']['status']!=='')$filters['status']=$state['filters']['status'];if($state['filters']['search']!=='')$filters['search']=$state['filters']['search'];
                $read('documents','wf_page',static fn(mixed $page):array=>$store->documentPage($filters,$page));
            }
            return $state;
        }catch(WorkflowException $failure){return self::failed($failure->getMessage(),str_contains($failure->getMessage(),'gefunden')||str_contains($failure->getMessage(),'Leseberechtigung')||str_contains($failure->getMessage(),'Schreibrechte')?403:422);}
    }
    private static function revision(string $source,string $message):?int
    {
        if($source==='')return null;
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$source))throw new WorkflowException($message);
        return (int)$source;
    }
}
