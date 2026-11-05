<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Verein\{Database,MembershipApplications};
$config=TestSupport::config();
if(!in_array($config['label']??'',['integrated','integrated-browser'],true))throw new RuntimeException('Nur registrierte integrierte Demo-Fixtures mit zuerst installiertem Managerschema.');
$db=TestSupport::db(); Database::migrate($db);
$db->exec("INSERT IGNORE INTO clubs(id,name) VALUES('synthetic-a','Synthetischer Kulturverein')");
$db->exec("INSERT IGNORE INTO actors(id,club_id,role,is_superuser) VALUES('native-membership-demo-staff','synthetic-a','members',1)");
Database::ensureContributionRates($db);
$staff=new MembershipApplications($db,'synthetic-a','native-membership-demo-staff');
if($staff->settings()['version']===0){$settings=$staff->settings()['config'];$settings['enabled']=true;$staff->saveSettings($settings,0);}
$pages=new Pages($db,new Access($db,'admin'));
if(!$pages->publicPage('mitglied-werden')&&!array_filter($pages->list(),fn($p)=>$p['slug']==='mitglied-werden')){
    $p=$pages->save(['title'=>'Mitglied werden','slug'=>'mitglied-werden','nav_label'=>'Mitglied werden','nav_order'=>40,'excerpt'=>'Kultur gemeinsam möglich machen. Der Antrag wird direkt vom Vereinsmanager erzeugt.']);
    $pages->publish($p['id'],$p['version']);
}
echo "Mitgliedsantrag an isolierten lokalen Vereinsmanager angebunden; bestehende Einstellungen erhalten.\n";
