<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\{Pages,Media};
use Kulturhus\Native\Communication\Workspace;
$db=TestSupport::db();TestSupport::seed($db);
Kulturhus\Native\Cms\Schema::install($db);
Kulturhus\Native\Communication\Schema::install($db);
Kulturhus\Native\Chat\Schema::install($db);
$access=new Access($db,'admin');$pages=new Pages($db,$access);
$config=TestSupport::config();$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];
if(!$pages->list()){
    $media=new Media($db,$access,$runtime.'/media');
    $im=imagecreatetruecolor(1200,700);$bg=imagecolorallocate($im,36,86,64);$gold=imagecolorallocate($im,212,164,76);$cream=imagecolorallocate($im,246,243,233);
    imagefilledrectangle($im,0,0,1199,699,$bg);imagefilledellipse($im,860,290,310,310,$gold);imagefilledrectangle($im,150,430,1050,480,$cream);imagestring($im,5,80,80,'KULTURHUS - SYNTHETISCHE GALERIE',$cream);
    $file=$runtime.'/synthetic-stage.png';imagepng($im,$file);unset($im);
    $asset=$media->upload($file,'synthetic-stage.png','Synthetische Illustration: grüne Bühne und goldener Kreis');unlink($file);
    $data=[
        ['title'=>'Kultur, die uns verbindet.','slug'=>'start','excerpt'=>'Musik, Begegnungen und Geschichten. Ein Haus für die Menschen unserer Region.','body'=>"Willkommen in der lokalen Kulturhus-Demo.\n\nHier entsteht ein eigener Ort für die Geschichten unseres Vereins: unabhängig redigiert, bewusst veröffentlicht und gemeinsam gestaltet.\n\nAlle Inhalte, Veranstaltungen und Personen dieser Demo sind synthetisch.",'nav_label'=>'Start','nav_order'=>0],
        ['title'=>'Ein Abend voller Musik','slug'=>'veranstaltungen','template'=>'event','excerpt'=>'Ein synthetischer Kulturabend zum Kennenlernen der neuen Website.','body'=>"Die Bühne ist bereitet. Gemeinsam entdecken wir neue Klänge und verbringen einen guten Abend miteinander.\n\nDies ist eine Testveranstaltung. Es gibt keine Buchung und keinen externen Ticketverkauf.",'event_start'=>'2026-11-14T19:30','event_location'=>'Synthetischer Kulturraum','nav_label'=>'Veranstaltungen','nav_order'=>10],
        ['title'=>'Einblicke ins Kulturhus','slug'=>'galerie','template'=>'gallery','excerpt'=>'Kleine Perspektiven auf das, was uns zusammenbringt.','body'=>'Synthetisches Testbild für Upload, Bildvarianten und responsive Darstellung.','media_ids'=>[$asset['id']],'nav_label'=>'Galerie','nav_order'=>20],
        ['title'=>'Kultur braucht Menschen','slug'=>'verein','excerpt'=>'Zeit teilen. Ideen mitbringen. Gemeinsam etwas bewegen.','body'=>"Ein Verein lebt von den Menschen, die ihn gestalten.\n\nDiese Seite demonstriert die redaktionelle Pflege einer Inhaltsseite. Reale Vereinsdaten werden hier nicht verwendet.",'nav_label'=>'Verein','nav_order'=>30],
    ];
    foreach($data as $input){$p=$pages->save($input);$pages->publish($p['id'],$p['version']);}
}
foreach(['newsletter','social','press'] as $module){$w=new Workspace($db,$access,$module);if(!$w->list())$w->save(['title'=>match($module){'newsletter'=>'Kulturpost · Herbst 2026','social'=>'Vorfreude auf unseren Kulturabend',default=>'Ein Haus für gemeinsame Kultur'},'body'=>"Synthetischer Redaktionsentwurf.\nAlle Daten bleiben lokal. Es wird nichts versendet."]);}
$chat=new Kulturhus\Native\Chat\Workspace($db,$access);
if(!$chat->list()){$thread=$chat->create('Planung des Kulturabends',['editor','reader']);$chat->send($thread['id'],'Willkommen im lokalen Vereinschat. Hier stimmen wir unseren synthetischen Kulturabend ab.',bin2hex(random_bytes(16)));}
echo "Synthetische native Demo initialisiert; vorhandene Inhalte bleiben erhalten.\n";
