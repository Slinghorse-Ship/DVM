<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\{Pages,Media,Schema};
$db=TestSupport::db();Schema::install($db);$access=new Access($db,'admin');$pages=new Pages($db,$access);
$config=TestSupport::config();$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];$source=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/demo-images';
$marker=$runtime.'/gallery-demo-seeded.json';if(is_file($marker)){echo "Galerie-Demobestand bleibt erhalten.\n";exit;}
foreach(['sommerbuehne.png','musik-im-gruenen.png','abendkonzert.png'] as $file)if(!is_file($source.'/'.$file))throw new RuntimeException('Synthetische Demonstrationsbilder fehlen.');
$media=new Media($db,$access,$runtime.'/media');$ids=[];
$assets=[['sommerbuehne.png','Musik und Begegnungen unter freiem Himmel'],['musik-im-gruenen.png','Akustische Klänge im Grünen'],['abendkonzert.png','Ein gemeinsamer Abend im Lichtergarten']];
foreach($assets as [$file,$caption]){$m=$media->upload($source.'/'.$file,$file,$caption.' · KI-generiertes Testbild');$ids[]=$m['id'];}
$albums=[
    ['title'=>'Sommermusik unter freiem Himmel','slug'=>'galerie/sommermusik','excerpt'=>'Musik, Begegnungen und gute Gespräche – ein sommerlicher Blick ins Vereinsleben.','body'=>'Dieses Album enthält ausschließlich KI-generierte Demonstrationsbilder.','template'=>'gallery','nav_visible'=>false,'nav_order'=>10,'media_ids'=>$ids,'gallery_items'=>array_map(static fn($id,$a)=>['id'=>$id,'caption'=>$a[1],'tags'=>['Synthetisch','Sommer','Musik']],$ids,$assets)],
    ['title'=>'Ein Abend voller Kultur','slug'=>'galerie/abendkultur','excerpt'=>'Wenn es dunkel wird, bleiben die schönsten Momente.','body'=>'Dieses Album enthält ausschließlich KI-generierte Demonstrationsbilder.','template'=>'gallery','nav_visible'=>false,'nav_order'=>20,'media_ids'=>[$ids[2],$ids[1]],'gallery_items'=>[['id'=>$ids[2],'caption'=>'Lichter, Musik und Zeit füreinander','tags'=>['Synthetisch','Abend']],['id'=>$ids[1],'caption'=>'Gemeinsam neue Klänge entdecken','tags'=>['Synthetisch','Akustik']]]],
];$created=[];
foreach($albums as $a){$existing=array_values(array_filter($pages->list(),static fn($p)=>$p['slug']===$a['slug']));if($existing){$created[]=$existing[0]['id'];continue;}$p=$pages->save($a);$pages->publish($p['id'],$p['version']);$created[]=$p['id'];}
file_put_contents($marker,json_encode(['synthetic'=>true,'source'=>'built-in image_gen, 2026-10-07','albums'=>$created,'media'=>$ids],JSON_THROW_ON_ERROR));
echo "Zwei synthetische Beispielalben angelegt. Bestehende Seiten und Alben bleiben erhalten.\n";
