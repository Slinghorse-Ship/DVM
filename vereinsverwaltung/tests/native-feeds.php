<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';

use Kulturhus\Native\Cms\{DataModel,Pages,Schema};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Web\Feeds;

$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL '.$label); ++$checks; echo 'PASS '.$label."\n"; }
function rejects(callable $operation, string $label): void { try {$operation();} catch (InvalidArgumentException|DomainException) {check(true,$label);return;} throw new RuntimeException('FAIL '.$label); }
function rssDocument(string $xml): DOMDocument {
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml,LIBXML_NONET)) throw new RuntimeException('RSS invalid XML');
    return $doc;
}
function unfold(string $ics): string { return preg_replace('/\r\n[ \t]/','',$ics); }

$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
$run = bin2hex(random_bytes(4));
foreach (['admin','editor','reader'] as $actor) Db::run($db,'UPDATE native_users SET club_id=? WHERE id=?',['feed-test-'.$run,$actor]);
Db::run($db,'UPDATE native_users SET club_id=? WHERE id=?',['feed-other-'.$run,'outsider']);
$access = new Access($db,$actors['admin']);
$pages = new Pages($db,$access);
$other = new Pages($db,new Access($db,$actors['outsider']));
$feeds = new Feeds($pages,'http://127.0.0.1:8190');
check(rssDocument($feeds->rss())->getElementsByTagName('item')->length === 0,'empty RSS is valid XML');
check(rssDocument($feeds->rss())->getElementsByTagName('title')->item(0)->textContent === 'Mein Verein · Veröffentlichungen','unconfigured RSS has a neutral club label');
$namedFeed = new Feeds($pages, 'http://127.0.0.1:8190', 'Sport & Musik <Gemeinsam>');
check(rssDocument($namedFeed->rss())->getElementsByTagName('title')->item(0)->textContent === 'Sport & Musik <Gemeinsam> · Veröffentlichungen','configured club identity stays plain XML text');
check(json_decode($feeds->eventsJson(),true,512,JSON_THROW_ON_ERROR)['events'] === [],'empty events JSON is valid');
check(str_starts_with($feeds->ics(),"BEGIN:VCALENDAR\r\n") && !str_contains($feeds->ics(),'BEGIN:VEVENT'),'empty calendar has no invented event');

$hidden = $pages->save(['title'=>'PRIVATE-DRAFT-ONLY','slug'=>'private','body'=>'DRAFT-SECRET']);
$foreign = $other->save(['title'=>'OTHER-CLUB-ONLY','slug'=>'foreign','body'=>'FOREIGN-SECRET']);
$other->publish($foreign['id'],1);
$pub = $pages->save(['title'=>'Öffentlicher Titel & <Kultur>','slug'=>'public-original','body'=>'Öffentlicher Inhalt mit MÜLLER und literal.* Suchtext.','excerpt'=>'<script>alert("RSS")</script> & Text','nav_visible'=>false]);
$pub = $pages->publish($pub['id'],1);
$publicVersion = $pub['version'];
$pub = $pages->save(array_replace($pub,['title'=>'PRIVATE-RENAMED-TITLE','slug'=>'private-rename','body'=>'PRIVATE-RENAMED-BODY','excerpt'=>'PRIVATE-RENAMED-SUMMARY','template'=>'event','event_start'=>'2030-01-01T11:00']),$pub['version']);
$rss = $feeds->rss();
$doc = rssDocument($rss);
check($doc->getElementsByTagName('item')->length === 1,'RSS includes non-navigation published page only');
check(!str_contains($rss,'PRIVATE') && !str_contains($rss,'FOREIGN'),'RSS does not leak draft, draft rename or other club');
check($doc->getElementsByTagName('item')->item(0)->getElementsByTagName('title')->item(0)->textContent === 'Öffentlicher Titel & <Kultur>','XML escapes round-trip UTF-8 and markup');
check(str_contains($rss,'/site/public-original') && !str_contains($rss,'/site/private-rename'),'RSS URL uses published slug');
check($doc->getElementsByTagName('script')->length === 0 && str_contains($doc->getElementsByTagName('description')->item(1)->textContent,'&lt;script&gt;'),'RSS description keeps plain text inert in HTML-aware readers');
$oldGuid = $doc->getElementsByTagName('guid')->item(0)->textContent;
check($feeds->search('müller')[0]['slug'] === 'public-original','search case-insensitive Unicode body and public URL');
check($feeds->search('öffentlicher')[0]['title'] === 'Öffentlicher Titel & <Kultur>','search matches published title');
check(count($feeds->search('RSS')) === 1,'search matches published excerpt');
check(count($feeds->search('literal.*')) === 1 && $feeds->search('literal.+') === [],'search treats regex characters literally');
check($feeds->search('PRIVATE') === [] && $feeds->search('FOREIGN') === [],'search excludes unpublished and foreign fields');
check($feeds->search('   ') === [],'empty search returns no listing');
rejects(fn()=>$feeds->search(str_repeat('x',121)),'overlong search rejected');
rejects(fn()=>$feeds->search("bad\nquery"),'search control characters rejected');
rejects(fn()=>$feeds->search("\xff"),'invalid UTF-8 search rejected');
check(json_decode($feeds->eventsJson(),true,512,JSON_THROW_ON_ERROR)['events'] === [],'draft switch to event does not enter event feed');

$eventTitle = 'Synthetisch, Konzert; '.str_repeat('Grüße 🎶 ',15);
$eventBody = "Öffentlich\\Text, mit; Zeichen\r\nBEGIN:VEVENT\r\nSUMMARY:Injected\r\nEND:VEVENT\n<script>danger()</script>";
$event = $pages->save(['title'=>$eventTitle,'slug'=>'event-original','body'=>$eventBody,'excerpt'=>'Kalender & Test','template'=>'event','event_start'=>'2026-10-07T19:30','event_location'=>'Saal, Bühne; Test\\Haus']);
$event = $pages->publish($event['id'],1);
$eventPublicVersion = $event['version'];
$oldEventUid = $event['id'].'@kulturhus-native.invalid';
$event = $pages->save(array_replace($event,['slug'=>'event-future','title'=>'PRIVATE-EVENT-TITLE','event_start'=>'2027-01-01T08:00','body'=>'PRIVATE-EVENT-BODY']),$event['version']);
$winter = $pages->save(['title'=>'Winter-Test','slug'=>'winter','template'=>'event','event_start'=>'2026-12-15T19:30','body'=>'Synthetisch']);
$winter = $pages->publish($winter['id'],1);
$undated = $pages->save(['title'=>'Termin noch offen','slug'=>'undated','template'=>'event']);
$undated = $pages->publish($undated['id'],1);
$json = $feeds->eventsJson();
$events = json_decode($json,true,512,JSON_THROW_ON_ERROR)['events'];
check(count($events) === 3,'event JSON includes only published event templates');
check($events[0]['id'] === $event['id'] && $events[0]['start_utc'] === '2026-10-07T17:30:00Z','event JSON summer Berlin start converts to UTC and sorts');
check($events[1]['start_utc'] === '2026-12-15T18:30:00Z','event JSON winter Berlin start converts to UTC');
check($events[2]['start_utc'] === null && $events[2]['start_local'] === '','undated event remains undated');
check($events[0]['published_version'] === $eventPublicVersion && $events[0]['slug'] === 'event-original','JSON uses immutable published version and slug');
check(!str_contains($json,'PRIVATE') && !str_contains($json,'FOREIGN') && !str_contains($json,'<script>'),'JSON excludes private data and escapes HTML-sensitive characters');
check($events[0]['title'] === trim($eventTitle) && str_contains($events[0]['description'],'<script>'),'JSON round-trips public text without executing it');
check(!isset($events[0]['draft_json']) && !isset($events[0]['custom_fields']) && !isset($events[0]['actor_id']),'JSON public projection excludes internal properties');
$ics = $feeds->ics();
$unfolded = unfold($ics);
check(substr_count($unfolded,'BEGIN:VEVENT' . "\r\n") === 2,'ICS emits exactly dated public events');
check(!str_contains($unfolded,'PRIVATE') && !str_contains($unfolded,'FOREIGN'),'ICS excludes draft values and foreign club');
check(str_contains($unfolded,'DTSTART:20261007T173000Z') && str_contains($unfolded,'DTSTART:20261215T183000Z'),'ICS uses UTC timestamps for summer and winter');
check(str_contains($unfolded,'UID:'.$oldEventUid),'ICS stable UID is page-based');
check(str_contains($unfolded,'SUMMARY:Synthetisch\\, Konzert\\;'),'ICS escapes commas and semicolons');
check(str_contains($unfolded,'LOCATION:Saal\\, Bühne\\; Test\\\\Haus'),'ICS escapes location backslash and separators');
check(str_contains($unfolded,'\\nBEGIN:VEVENT\\nSUMMARY:Injected\\nEND:VEVENT'),'ICS escapes injected content lines');
check(!preg_match('/(?<!\r)\n|\r(?!\n)/',$ics),'ICS only CRLF line separators');
check((bool)preg_match('/DTSTAMP:\d{8}T\d{6}Z\r\n/',$unfolded),'ICS includes UTC DTSTAMP');
$lines = explode("\r\n",trim($ics,"\r\n"));
check(max(array_map(strlen(...),$lines)) <= 75,'all ICS physical lines are at most 75 octets');
check(count(array_filter($lines,static fn($line)=>str_starts_with($line,' '))) > 0,'long Unicode properties use continuation folds');
check(!array_filter($lines,static fn($line)=>preg_match('//u',$line)!==1),'ICS folds preserve complete UTF-8 code points');
check(str_contains($unfolded,str_repeat('Grüße 🎶 ',14)),'ICS unfolding preserves long Unicode summary');

$event = $pages->publish($event['id'],$event['version']);
$updated = json_decode($feeds->eventsJson(),true,512,JSON_THROW_ON_ERROR)['events'];
$updatedEvent = array_values(array_filter($updated,fn($r)=>$r['id'] === $event['id']))[0];
check($updatedEvent['uid'] === $oldEventUid && str_ends_with($updatedEvent['url'],'/site/event-future'),'republish rename preserves UID and updates public URL');
check(str_contains(unfold($feeds->ics()),'SEQUENCE:'.$event['version']),'ICS sequence advances with published version');
$event = $pages->unpublish($event['id'],$event['version']);
check(!str_contains($feeds->rss(),$oldEventUid) && !str_contains($feeds->ics(),$oldEventUid) && !str_contains($feeds->eventsJson(),$oldEventUid),'unpublish removes page from every feed immediately');
check($feeds->search('PRIVATE-EVENT-TITLE') === [],'unpublished formerly public event disappears from search');
$pub = $pages->save(array_replace($pub,['title'=>'Öffentliche neue Fassung','body'=>'Nun freigegeben','excerpt'=>'','template'=>'standard','event_start'=>'']),$pub['version']);
$pub = $pages->publish($pub['id'],$pub['version']);
$rssDoc = rssDocument($feeds->rss());
$newItem = $rssDoc->getElementsByTagName('item')->item(0);
check($newItem->getElementsByTagName('guid')->item(0)->textContent === $oldGuid,'RSS newest publication first and guid stable after rename');

// Custom renderer is captured by the data model in the public revision, never looked up live.
$model = new DataModel($db,$access);
$model->saveTemplate(['key'=>'concert','label'=>'Eigener Konzerttyp','renderer'=>'event','fields'=>[]]);
$custom = $pages->save(['title'=>'Eigene öffentliche Veranstaltung','slug'=>'custom-event','template'=>'concert','event_start'=>'2027-02-01T12:00']);
$custom = $pages->publish($custom['id'],1);
check(str_contains($feeds->eventsJson(),$custom['id']),'custom published event renderer included');
$model->saveTemplate(['key'=>'concert','label'=>'Jetzt Standard','renderer'=>'standard','fields'=>[]],1);
check(str_contains($feeds->eventsJson(),$custom['id']),'later template edit cannot change public snapshot event classification');

for ($i=0;$i<52;++$i) {
    $row = $pages->save(['title'=>'Suchgrenze '.$i,'slug'=>'limit-'.$i,'body'=>'BOUNDED-NEEDLE']);
    $pages->publish($row['id'],1);
}
check(count($feeds->search('BOUNDED-NEEDLE')) === 50,'search result is bounded to 50 public pages');
check((new Feeds($other,'http://127.0.0.1:8190'))->search('BOUNDED-NEEDLE') === [],'feed search retains tenant scope');
foreach (['http://example.invalid','https://user:pass@example.invalid','https://example.invalid/path','https://example.invalid?x=1',"http://127.0.0.1\r\nInjected:x",'javascript:bad'] as $origin) rejects(fn()=>new Feeds($pages,$origin),'unsafe trusted origin rejected');
check(str_contains((new Feeds($pages,'https://example.invalid/'))->rss(),'https://example.invalid/site/'),'trusted HTTPS origin produces links without requests');

echo "$checks native feed checks passed; isolated synthetic MySQL, no external calls or publication.\n";
