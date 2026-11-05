<?php declare(strict_types=1);
/** Explicit import into the registered loopback-only synthetic fixture. Never replaces a saved template. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../tests/support/document-workflow-fixture.php';
require_once __DIR__.'/../tests/support/document-workflow-demo-access.php';
require_once __DIR__.'/../src/DocumentWorkflow/WorkflowStore.php';
$lock=DocumentWorkflowFixture::initializationLock();
try {
    $registration=DocumentWorkflowFixture::demoRegistration();
    $store=new \Kulturhus\Verein\DocumentWorkflow\WorkflowStore(DocumentWorkflowFixture::connection($registration['database']),new DocumentWorkflowDemoAccess());
    $resource=json_decode(file_get_contents(__DIR__.'/../resources/document-workflows/rwe-assessment-de.json'),true,64,JSON_THROW_ON_ERROR);
    $id=null;foreach($store->templates() as $template)if(str_starts_with($template['provenance']['adaptation']??'','rwe-assessment-de-v1;')){$id=$template['id'];break;}
    if(!$id){unset($resource['key']);$id=$store->saveTemplate('',0,$resource);$store->publishTemplate($id,1);}
    $documents=$store->documents(['template_id'=>$id]);
    if(!$documents){
        $doc=$store->createDocument($id,'','Gefährdungsbeurteilung · Kulturabend (Testdaten)');
        $store->saveDocument($doc,1,['document_ref'=>'KH-GB-TEST-001','assessment_title'=>'Kulturabend (Testdaten)','location'=>'Synthetischer Veranstaltungsraum','activity'=>'Bühnenaufbau und Veranstaltung','equipment'=>'Kabel, Podeste und Beleuchtung','task_step'=>'Vorbereitung','assessor'=>'Mara Muster · Testdaten','review_date'=>'2026-10-10','risk_register'=>[
            ['category'=>'Arbeitsumgebung','hazard'=>'Stolpern über lose Leitungen','impact'=>'Sturzverletzung','initial'=>['severity'=>3,'likelihood'=>3],'controls'=>'Kabelbrücken verwenden. Laufwege freihalten und vor Öffnung prüfen.','residual'=>['severity'=>2,'likelihood'=>1],'responsible'=>'Mara Muster · Testdaten','effectiveness'=>'Sichtkontrolle vor Beginn durch Alex Beispiel · Testdaten'],
        ]],'Gefährdungsbeurteilung · Kulturabend (Testdaten)','');
    }else $doc=$documents[0]['id'];
    $report=['template'=>$id,'document'=>$doc,'resource_sha256'=>hash_file('sha256',__DIR__.'/../resources/document-workflows/rwe-assessment-de.json'),'production_changed'=>false];
    file_put_contents(DocumentWorkflowFixture::registryDirectory().'/rwe-import.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}
