<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Settings,SettingsSchema,DocumentPreview};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1)");
$settings=new Settings($db,'admin');
foreach(['texts_contributions'=>'Vereinsbeitrag · Muster','texts_invoices'=>'Vereinsleistung · Muster','texts_donations'=>'Geldspende · Muster'] as $group=>$expected){
 $templates=SettingsSchema::defaults($group);
 foreach($templates as &$template){$template['subject']='{{BEZEICHNUNG}}';$template['message']='{{BEZEICHNUNG}} · {{ZAHLBETRAG}}';}unset($template);
 foreach(array_keys($templates) as $key){$result=DocumentPreview::build($settings,null,$group,$key,$templates);if($result['text']['subject']!==$expected||$result['text']['message']!==$expected.' · 120,00 €'||$result['values']['BEZEICHNUNG']!==$expected)throw new RuntimeException('Incorrect group-specific designation');echo "PASS $group / $key uses correct sample designation\n";}
}
