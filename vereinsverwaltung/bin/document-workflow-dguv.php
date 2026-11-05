<?php declare(strict_types=1);
/** Explicit, idempotent import into this chat's registered synthetic MySQL demo only. */
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
    $db=DocumentWorkflowFixture::connection($registration['database']);
    $store=new WorkflowStore($db,new DocumentWorkflowDemoAccess());
    $raw=@file_get_contents(__DIR__.'/../resources/document-workflows/de-bg-templates.json');
    if($raw===false)throw new RuntimeException('Deutsche Vorlagen fehlen.');
    $templates=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($templates)||!array_is_list($templates)||count($templates)!==7)throw new RuntimeException('Sieben DGUV-Vorlagen erforderlich.');
    $folderName='Verein · Deutschland / DGUV';$folder=null;
    foreach($store->folders() as $row)if($row['name']===$folderName&&$row['parent_id']===''){$folder=$row['id'];break;}
    $folder??=$store->saveFolder('',0,$folderName);
    $created=0;$existing=0;$result=[];
    foreach($templates as $template){
        if(!is_string($template['key']??null)||!preg_match('/^de-[a-z-]+$/D',$template['key']))throw new RuntimeException('Ungültige Ressource.');
        $hasRegister=in_array('risk_register',array_column($template['fields'],'type'),true);
        // A distinct resource generation leaves existing user edits and pinned documents intact.
        $marker='Kulturhus-Demo-Ressource: '.$template['key'].($hasRegister?':register-v1':'');
        $data=array_intersect_key($template,array_flip(['title','description','fields','provenance']));
        if($hasRegister)$data['title'].=' · Tabellenfassung';
        $data['provenance']['adaptation']=trim(($data['provenance']['adaptation']??'').' '.$marker);
        // This marker permits recovery after interruption without replacing edited templates.
        $id=null;
        foreach($store->templates() as $row)if(str_ends_with($row['provenance']['adaptation']??'',$marker)){$id=$row['id'];break;}
        if($id===null){$id=$store->saveTemplate('',0,$data);$created++;}else{$existing++;}
        $row=$store->template($id);
        if((int)$row['published_version']===0)$store->publishTemplate($id,$row['version']);
        $docs=$store->documents(['template_id'=>$id]);
        if(!$docs){
            $doc=$store->createDocument($id,$folder,'Demo · '.$template['title']);
            $document=$store->document($doc);$values=[];
            foreach($document['fields'] as $field){
                if($field['type']==='risk_register')$values[$field['key']]=[
                    ['category'=>'Demo · Arbeitsumgebung','hazard'=>'Stolperstelle an einem vorübergehend verlegten Kabel – synthetisches Beispiel.','impact'=>'Sturz und Verletzung einer anwesenden Person.','initial'=>null,'controls'=>'Vor Ort festlegen und prüfen.','residual'=>null,'responsible'=>'','effectiveness'=>''],
                    ['category'=>'Demo · Organisation','hazard'=>'Unklare Zuständigkeit bei einer Störung – synthetisches Beispiel.','impact'=>'Verzögerte Reaktion und unkoordinierte Maßnahmen.','initial'=>null,'controls'=>'Verantwortung und Meldewege vor Ort festlegen.','residual'=>null,'responsible'=>'','effectiveness'=>''],
                ];
                elseif($field['key']==='organisation')$values[$field['key']]='Kulturhus · synthetische Vereinsdemo';
                elseif($field['key']==='location')$values[$field['key']]='Demo-Veranstaltungsraum';
                elseif($field['key']==='work_phase')$values[$field['key']]='Vorbereitung, Durchführung und Abschluss';
                elseif($field['key']==='initial_risk')$values[$field['key']]=['likelihood'=>3,'severity'=>3];
                elseif($field['key']==='risk_scope')$values[$field['key']]='Synthetisches Einzelrisiko G1 für die Bedienvorschau. Keine reale betriebliche Beurteilung.';
                elseif(in_array($field['key'],['activity','task'],true))$values[$field['key']]='Synthetische Demo: '.$template['title'];
            }
            if($values)$store->saveDocument($doc,$document['version'],$values,$document['title'],$folder);
        }else{$doc=$docs[0]['id'];}
        $result[]=['key'=>$template['key'],'template'=>$id,'document'=>$doc];
    }
    // Non-sensitive inspection result, independent from immutable connection registrations.
    $report=['source_sha256'=>hash('sha256',$raw),'folder'=>$folder,'resources'=>$result,'created'=>$created,'existing'=>$existing,'checked_at'=>gmdate('c')];
    $path=DocumentWorkflowFixture::registryDirectory().'/dguv-import.json';
    $bytes=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Importnachweis nicht schreibbar.');
    echo 'DGUV-Demo bereit: '.$created.' neue, '.$existing.' vorhandene Vorlagen; 7 Tätigkeits-/Kurzcheck-Dokumente. Bestehende Inhalte unverändert.' . "\n";
}catch(Throwable $error){fwrite(STDERR,"DGUV-Demo-Import konnte nicht abgeschlossen werden. Bereits gespeicherte Arbeit bleibt erhalten.\n");error_log('Workflow DGUV import: '.get_class($error));exit(1);}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
