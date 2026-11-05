<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/native/bootstrap.php';
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/Documents/ManagerDocumentWorkflows.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\DocumentWorkflow\WorkflowSchema;
$config=TestSupport::config();if(($config['label']??'')!=='workflow-manager')throw new RuntimeException('Eigene Workflow-Manager-Fixture erforderlich.');
$db=TestSupport::db();WorkflowSchema::install($db);
$module=new Kulturhus\Verein\ManagerDocumentWorkflows($db,'manager-admin');$store=$module->store;
$template=null;foreach($store->templates() as $t)if(str_starts_with($t['provenance']['adaptation']??'','rwe-assessment-de-v1;')){$template=$t['id'];break;}
if(!$template){$resource=json_decode(file_get_contents(dirname(__DIR__).'/resources/document-workflows/rwe-assessment-de.json'),true,64,JSON_THROW_ON_ERROR);unset($resource['key']);$template=$store->saveTemplate('',0,$resource);$store->publishTemplate($template,1);}
$documents=$store->documents(['template_id'=>$template]);
if(($argv[1]??'')==='new-test')$documents=[]; // Append an isolated test case; never reset existing reviews.
if(!$documents){
    $doc=$store->createDocument($template,'','Gefährdungsbeurteilung · Kulturabend (Testdaten)');
    $store->saveDocument($doc,1,['document_ref'=>'KH-GB-DEMO-001','assessment_title'=>'Kulturabend','location'=>'Synthetischer Vereinsraum','activity'=>'Bühnenaufbau','equipment'=>'Kabel und Podeste','task_step'=>'Vorbereitung','assessor'=>'Jan Beispiel','review_date'=>'2026-10-10','risk_register'=>[['category'=>'Arbeitsumgebung','hazard'=>'Lose Kabel','impact'=>'Sturz','initial'=>['severity'=>3,'likelihood'=>3],'controls'=>'Kabelbrücken verwenden; Laufwege freihalten.','residual'=>['severity'=>2,'likelihood'=>1],'responsible'=>'Jan Beispiel','effectiveness'=>'Sichtprüfung vor Beginn']]],'Gefährdungsbeurteilung · Kulturabend (Testdaten)','');
    $store->transition($doc,2,'submit');$store->transition($doc,3,'approve');$module->signatures->request($doc,4,['manager-member']);
}else $doc=$documents[0]['id'];
$result=['document'=>$doc,'fixture'=>$config['label'],'production_changed'=>false];
file_put_contents(dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/workflow-manager-runtime/workflow-demo.json',json_encode($result,JSON_THROW_ON_ERROR));echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
