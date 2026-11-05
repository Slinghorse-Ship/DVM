<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\DocumentWorkflow\WorkflowSignatures;

/** Read one personally assigned original, or one bounded personal metadata page. */
final class WorkflowSignatureState
{
    public static function prepare(WorkflowSignatures $signatures,array $query,array $post,string $action):array
    {
        $text=static function(array $input,string $key):string{if(!array_key_exists($key,$input))return '';if(!is_string($input[$key]))throw new \DomainException('Unterschriftenauswahl prüfen.');return $input[$key];};
        $state=['request'=>null,'page'=>null,'id'=>'','attempt'=>false,'image'=>'','accepted'=>false,'hash'=>'','hash_matches'=>true,'error'=>'','http_status'=>0];
        try{
            $state['id']=in_array($action,['document-workflow-signature-sign','document-workflow-signature-archive'],true)?$text($post,'wf_sign'):$text($query,'wf_sign');
            if($state['id']!==''){
                try{$request=$signatures->ownRequest($state['id']);}catch(\DomainException $failure){$state['http_status']=403;throw $failure;}
                $state['request']=$request;$state['hash']=$request['document_sha256'];
                $state['attempt']=$action==='document-workflow-signature-sign'&&$text($post,'wf_sign')===$state['id'];
                if($state['attempt']){
                    $state['hash']='';$state['hash_matches']=false;$state['hash']=$text($post,'wf_document_hash');$state['hash_matches']=hash_equals($request['document_sha256'],$state['hash']);
                    $state['image']=WorkflowSignatureForm::retained($post,$request);$state['accepted']=$state['hash_matches']&&$text($post,'wf_accepted')==='1';
                }
            }else $state['page']=$signatures->ownPage($query['wf_signatures_page']??1);
        }catch(\DomainException $failure){$state['error']=$failure->getMessage();if($state['http_status']===0)$state['http_status']=422;}
        return $state;
    }
}
