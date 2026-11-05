<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require dirname(__DIR__,2).'/src/bootstrap.php';
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\ManagerDocumentWorkflows;
use Kulturhus\Verein\DocumentWorkflow\WorkflowSchema;
if ((TestSupport::config()['label'] ?? '') !== 'integrated-manager') throw new RuntimeException('Own integrated synthetic fixture required.');
$db=TestSupport::db();
WorkflowSchema::install($db);
$store=(new ManagerDocumentWorkflows($db,'manager-admin'))->store;
$directory=dirname(__DIR__,3).'/.local/browser-tests';
if (!is_dir($directory)) mkdir($directory,0700,true);
$path=$directory.'/calendar-workflows.json';
if (is_file($path)) {
    $fixture=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    $document=$store->document($fixture['document']);
    if ((int)$document['version'] !== $fixture['revision']) throw new RuntimeException('Existing synthetic calendar document changed; inspect before replacing.');
    echo "Existing original HSE calendar fixture.\n";
    exit();
}
$template=$store->saveTemplate('',0,['title'=>'Kalenderprüfung · synthetisch','fields'=>[
    ['key'=>'review_date','label'=>'Nächster Prüftermin','type'=>'date','required'=>true],
    ['key'=>'deadline','label'=>'Termin für die Klärung','type'=>'date','required'=>true],
]]);
$store->publishTemplate($template,1);
$date=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$document=$store->createDocument($template,'','Kalender-Bühnenbeurteilung');
$store->saveDocument($document,1,['review_date'=>$date,'deadline'=>$date],'Kalender-Bühnenbeurteilung','');
$store->transition($document,2,'submit'); $store->transition($document,3,'approve');
file_put_contents($path,json_encode(['document'=>$document,'revision'=>4,'date'=>$date],JSON_THROW_ON_ERROR));
echo "Original synthetic HSE calendar document ready.\n";
