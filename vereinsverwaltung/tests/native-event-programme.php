<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/editorial-browser-fixture.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Cms\{Pages,PathHistory};
use Kulturhus\Native\Events\Eventbrite;
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);$fixture=ownEditorialBrowserFixture($db);$access=$fixture['admin'];$pages=new Pages($db,$access);$browser=getenv('DVM_EVENT_PROGRAMME_BROWSER')==='1';
$media=new Kulturhus\Native\Cms\Media($db,$access,$fixture['root'].'/media');$pixels=imagecreatetruecolor(320,200);imagefill($pixels,0,0,imagecolorallocate($pixels,40,110,170));imagepng($pixels,$fixture['root'].'/event.png');imagedestroy($pixels);$photo=$media->upload($fixture['root'].'/event.png','event.png','Eigenes öffentliches Terminbild <Original>');$photoHashes=[];foreach(['original','thumb','medium','large']as$size){$variant=$media->variant($photo['id'],$size);$photoHashes[$variant['path']]=hash_file('sha256',$variant['path']);}
$existing = array_column($pages->list(), null, "slug");
if (!isset($existing["veranstaltungen"])) {
    $p = $pages->save([
        "slug" => "veranstaltungen",
        "title" => "Veranstaltungen",
        "excerpt" => "Vereinsprogramm",
    ]);
    $pages->publish($p["id"], $p["version"]);
}
$ids = [];
for ($i = 1; $i <= 24; $i++) {
    $slug = "veranstaltungen/programmbrowser-" . $i;
    $p =
        $existing[$slug] ??
        $pages->save([
            "slug" => $slug,
            "title" => "Programmprüfung " . $i,
            "template" => "event",
            "event_start" =>
                "2050-01-" .
                str_pad((string) ($i + 1), 2, "0", STR_PAD_LEFT) .
                "T19:00",
            "event_timezone" => "Europe/Berlin",
            "event_end" =>
                "2050-01-" .
                str_pad((string) ($i + 1), 2, "0", STR_PAD_LEFT) .
                "T20:30:00Z",
            "event_location" => "Synthetischer Vereinssaal",
            "event_address" => "Testweg 12",
            "body" =>
                "Eigenes synthetisches Terminoriginal für den Programm-Browser.",
            "media_ids" => $i===1?[$photo["id"]]:[],
            "event_price" => "0.00",
            "event_ticket_url" => "https://example.invalid/tickets",
        ]);
    if (!str_starts_with($p["title"], "Programmprüfung ")) {
        throw new RuntimeException("Prüfpfad bereits anderweitig belegt.");
    }
    $corrected = false;
    if ($p["nav_visible"] && !$p["has_unpublished_changes"]) {
        $p = $pages->save(
            array_replace($p, ["nav_visible" => false]),
            $p["version"],
        );
        $corrected = true;
    }
    if ($p["published_revision"] === null || $corrected) {
        $p = $pages->publish($p["id"], $p["version"]);
    }
    $ids[] = $p["id"];
}
$slug = "veranstaltungen/programmbrowser-archiv";
$p =
    $existing[$slug] ??
    $pages->save([
        "slug" => $slug,
        "title" => "Programmprüfung Archiv",
        "template" => "event",
        "event_start" => "2020-01-02T19:00",
        "event_location" => "Synthetischer Vereinssaal",
    ]);
if ($p["published_revision"] === null) {
    $pages->publish($p["id"], $p["version"]);
}
$raw = [
    "id" => "9000000099",
    "name" => ["text" => "Programmprüfung Absage"],
    "description" => ["text" => "Eigenes öffentliches Absageoriginal"],
    "start" => ["utc" => "2050-01-26T18:00:00Z", "timezone" => "Europe/Berlin"],
    "end" => ["utc" => "2050-01-26T20:30:00Z"],
    "venue" => [
        "name" => "Synthetischer Vereinssaal",
        "address" => ["localized_address_display" => "Testweg 12"],
    ],
    "status" => "live",
    "url" => "https://www.eventbrite.de/e/9000000099",
];
$cancel = $pages->events()->publicPage("veranstaltungen/eventbrite-9000000099");
if (!$cancel) {
    $importer = new Eventbrite($db, $access);
    $result = $importer->import([Eventbrite::normalize($raw)]);
    $p = $pages->get($result[0]["id"]);
    if ($p["published_revision"] === null) {
        $p = $pages->publish($p["id"], $p["version"]);
    }
    $raw["status"] = "canceled";
    $importer->import([Eventbrite::normalize($raw)]);
    $cancel = $pages->events()->publicId($p["id"]);
}
if (!$cancel || !$cancel["cancellation_notice"]) {
    throw new RuntimeException("Synthetisches Absageoriginal nicht verfügbar.");
}
$history = new PathHistory($db, $access);
$alias = "programmbrowser-absage-alt";
if (
    !in_array(
        $alias,
        array_column($history->listing($cancel["id"]), "slug"),
        true,
    )
) {
    $history->remember(
        $cancel["id"],
        $alias,
        $pages->get($cancel["id"])["version"],
    );
}
$data = [
    "programme" => "/veranstaltungen/?q=Programmpr%C3%BCfung&jahr=2050",
    "detail" => "/veranstaltungen/programmbrowser-1/",
    "archive" => "/veranstaltungen/?archiv=1&q=Programmpr%C3%BCfung&jahr=2020",
    "cancel" => "/veranstaltungen/eventbrite-9000000099/",
    "event_id" => $ids[0],
    "cancel_id" => $cancel["id"],
    "editor" => Kulturhus\Native\Integration\Routes::url("events", $ids[0]),
    "total" => 25,
];

$data['medium_sha']=hash_file('sha256',$media->variant($photo['id'],'medium')['path']);
$tables=['khcms_pages','khcms_revisions','khcms_events','khcms_media','native_event_sources','native_event_source_aliases','native_private_settings','native_delivery_jobs','club_settings','settings_roles','settings_role_assignments','actors','contacts','native_users','native_grants'];$editable=$data['event_id'];$filter=static fn(string$table):array=>in_array($table,['khcms_pages','khcms_revisions'],true)?[' WHERE '.($table==='khcms_pages'?'id':'page_id').'<>?',[$editable]]:['',[]];$before=ownOriginalDatabaseHash($db,$tables,$filter);$original=$pages->get($editable);$public=$pages->publicPage($original['slug']);$generation=$pages->generation();$keys=[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')];$server=null;
try{
 if($browser){$server=ownEditorialBrowserStart($fixture,$data);putenv('DVM_EVENT_PROGRAMME_FIXTURE='.getenv('DVM_EDITORIAL_WORKFLOW_FIXTURE'));$node=proc_open(['node',__DIR__.'/native-event-catalogue-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Own event programme browser failed.');}
 else{$listing=$pages->events()->listing(['jahr'=>'2050','q'=>'Programmprüfung'],new DateTimeImmutable('2026-10-15T12:00:00Z'));if($listing['total']!==25||count($listing['rows'])!==20)throw new RuntimeException('Own real published programme projection differs.');}
 if(ownOriginalDatabaseHash($db,$tables,$filter)!==$before)throw new RuntimeException('Outside event originals changed.');$current=$pages->get($editable);$expected=$original;if($browser&&$expected['body_html']==='')$expected['body_html']=Kulturhus\Native\Web\Html::content($original);foreach(['version','updated_at','has_unpublished_changes']as$key){unset($current[$key],$expected[$key]);}if($current!==$expected)throw new RuntimeException('Original event content changed: '.implode(',',array_keys(array_filter($current,static fn($value,$key)=>!array_key_exists($key,$expected)||$value!==$expected[$key],ARRAY_FILTER_USE_BOTH))));
 if($pages->publicPage($original['slug'])!==$public||$pages->generation()!==$generation)throw new RuntimeException('Actual public event original changed.');if($keys!==[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')])throw new RuntimeException('Own event authentication keys changed.');
 foreach($photoHashes as$file=>$hash)if(hash_file('sha256',$file)!==$hash)throw new RuntimeException('Original event image and variant bytes changed.');
 echo json_encode(['suite'=>'native-event-programme','browser'=>$browser,'public_events'=>26,'outside_originals'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}finally{if($server){proc_terminate($server['process']);proc_close($server['process']);fclose($server['log']);}}
