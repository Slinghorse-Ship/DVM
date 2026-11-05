<?php declare(strict_types=1);
/** Explicit import of ordinary documents into the registered local synthetic demo. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require_once __DIR__.'/../tests/support/document-workflow-fixture.php';
require_once __DIR__.'/../tests/support/document-workflow-demo-access.php';
require_once __DIR__.'/../src/DocumentWorkflow/WorkflowStore.php';
use Kulturhus\Verein\DocumentWorkflow\WorkflowStore;
$lock=null;
try{
    $lock=DocumentWorkflowFixture::initializationLock();
    $registration=DocumentWorkflowFixture::demoRegistration();
    $store=new WorkflowStore(DocumentWorkflowFixture::connection($registration['database']),new DocumentWorkflowDemoAccess());
    $raw=(string)file_get_contents(__DIR__.'/../resources/document-workflows/general-templates.json');
    $templates=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $folderName='Verein · Allgemeine Dokumente';$folder=null;
    foreach($store->folders() as $row)if($row['name']===$folderName&&$row['parent_id']===''){$folder=$row['id'];break;}
    $folder??=$store->saveFolder('',0,$folderName);
    $result=[];$created=0;
    foreach($templates as $template){
        $marker='Kulturhus-Demo-Ressource: '.$template['key'];
        $data=array_intersect_key($template,array_flip(['title','description','fields','provenance']));
        $data['provenance']['adaptation'].=' '.$marker;$id=null;
        foreach($store->templates() as $row)if(str_ends_with($row['provenance']['adaptation']??'',$marker)){$id=$row['id'];break;}
        if($id===null){$id=$store->saveTemplate('',0,$data);$created++;}
        $row=$store->template($id);
        if((int)$row['published_version']===0)$store->publishTemplate($id,$row['version']);
        $documents=$store->documents(['template_id'=>$id]);
        if(!$documents){
            $doc=$store->createDocument($id,$folder,'Demo · '.$template['title']);
            if($template['key']==='general-letter'){
                $row=$store->document($doc);
                $values=['date'=>'2026-10-07','recipient'=>'Demo-Verein\nMusteranschrift (synthetisch)','subject'=>'Einladung zum Austausch','salutation'=>'Liebe Beteiligte,','body'=>'dies ist ein frei bearbeitbarer Demo-Brief.\n\nErgänzt eure eigenen Inhalte und prüft die Live-Vorschau.','closing'=>'Freundliche Grüße','sender'=>'Demo-Vereinsbüro'];
                $values=array_map(static fn($value)=>str_replace('\\n',"\n",$value),$values);
                $store->saveDocument($doc,$row['version'],$values,$row['title'],$folder);
            }
        }else{$doc=$documents[0]['id'];}
        $result[]=['key'=>$template['key'],'template'=>$id,'document'=>$doc];
    }
    $report=['folder'=>$folder,'resources'=>$result,'created'=>$created,'source_sha256'=>hash('sha256',$raw),'checked_at'=>gmdate('c')];
    $bytes=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    if(file_put_contents(DocumentWorkflowFixture::registryDirectory().'/general-import.json',$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Importnachweis nicht schreibbar.');
    echo 'Allgemeine Dokumente bereit: '.$created." neue Vorlagen; Brief, Protokoll, Antrag und Checkliste.\n";
}catch(Throwable $error){fwrite(STDERR,"Allgemeine Demo-Vorlagen konnten nicht vollständig importiert werden. Vorhandene Inhalte bleiben erhalten.\n");error_log('Workflow general import: '.get_class($error));exit(1);}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
