<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\{Content,Schema,Workspace,Subscribers,PressContacts};

$db = TestSupport::db();
if (($argv[1] ?? '') === '--race-save') {
    $raceWorkspace = new Workspace($db,new Access($db,'admin'),'social');
    echo "ready\n";
    flush();
    try {
        $raceWorkspace->save(['id'=>$argv[2],'body'=>'Concurrent '.$argv[4]],(int)$argv[3]);
        echo "saved\n";
    } catch (DomainException) { echo "conflict\n"; }
    exit;
}
$actors = TestSupport::seed($db);
Schema::install($db);
$admin = new Access($db,$actors['admin']);
$editor = new Access($db,$actors['editor']);
$reader = new Access($db,$actors['reader']);
$outsider = new Access($db,$actors['outsider']);
$checks = 0;
function check(bool $valid,string $label): void { global $checks; if (!$valid) throw new RuntimeException($label); ++$checks; echo "PASS $label\n"; }
function denied(callable $fn,string $label): void { try { $fn(); } catch (DomainException) { check(true,$label); return; } throw new RuntimeException($label); }
// Namespaced actor clubs make this suite repeatable without deleting even synthetic data.
$run = bin2hex(random_bytes(4));
foreach (['admin','editor','reader'] as $actor) Db::run($db,'UPDATE native_users SET club_id=? WHERE id=?',['comms-'.$run,$actor]);
Db::run($db,'UPDATE native_users SET club_id=? WHERE id=?',['comms-other-'.$run,'outsider']);
$now = '2026-10-07T12:00:00Z';
$before = '2026-10-07T11:59:59Z';

foreach (Schema::MODULES as $module) {
    $workspace = new Workspace($db,$admin,$module);
    $edit = new Workspace($db,$editor,$module);
    $read = new Workspace($db,$reader,$module);
    $other = new Workspace($db,$outsider,$module);
    $draft = $edit->save(['title'=>'Synthetischer Test '.$module,'body'=>'Erste Fassung']);
    check($draft['status'] === 'draft' && $draft['version'] === 1,$module.' independent draft persists');
    check((new Workspace($db,$admin,$module))->get($draft['id'])['body'] === 'Erste Fassung',$module.' fresh service reads persisted content');
    denied(fn()=>$read->save(['title'=>'Unerlaubt']),$module.' reader cannot edit');
    denied(fn()=>$edit->approve($draft['id'],1),$module.' editor cannot grant publication approval');
    denied(fn()=>$other->get($draft['id']),$module.' cross-club read rejected');
    denied(fn()=>$other->save(['id'=>$draft['id'],'title'=>'Unerlaubt'],1),$module.' cross-club write rejected');
    denied(fn()=>$workspace->schedule($draft['id'],$now,1),$module.' unapproved scheduling rejected');
    $second = $edit->save(['id'=>$draft['id'],'body'=>'Zweite Fassung','status'=>'review'],1);
    denied(fn()=>$edit->save(['id'=>$draft['id'],'body'=>'Veraltet'],1),$module.' stale editor rejected');
    check(count($workspace->revisions($draft['id'])) === 2,$module.' historical versions retained');
    $approved = $workspace->approve($draft['id'],2);
    check($approved['approved_by'] === 'admin' && $approved['approved_version'] === 3,$module.' approval bound to exact version');
    denied(fn()=>$workspace->schedule($draft['id'],'2026-10-07 14:00',3),$module.' ambiguous timezone rejected');
    $scheduled = $workspace->schedule($draft['id'],'2026-10-07T14:00:00+02:00',3);
    check($scheduled['scheduled_at'] === '2026-10-07 12:00:00',$module.' schedule converts explicit Berlin offset to UTC');
    check($workspace->runDue($before)['prepared'] === 0,$module.' queue not run before due boundary');
    $result = $workspace->runDue($now);
    check($result['prepared'] === 1 && $result['sent'] === 0 && $result['transport'] === 'disabled',$module.' due boundary prepares locally without transport');
    check($workspace->get($draft['id'])['status'] === 'local_ready',$module.' ready status never claims sent');
    check($workspace->runDue($now)['prepared'] === 0,$module.' repeated scheduler does not duplicate');
    $current = $workspace->get($draft['id']);
    $edited = $edit->save(['id'=>$draft['id'],'body'=>'Noch einmal geändert'],$current['version']);
    check($edited['approved_by'] === null && $edited['status'] === 'draft',$module.' edits revoke approval');
    check($workspace->queue()[0]['status'] === 'cancelled',$module.' edits cancel old ready queue');
    $approved = $workspace->approve($draft['id'],$edited['version']);
    $scheduled = $workspace->schedule($draft['id'],$now,$approved['version']);
    $uncertain = $workspace->markAmbiguous($draft['id'],$scheduled['version']);
    denied(fn()=>$workspace->save(['id'=>$draft['id'],'body'=>'Retry'],$uncertain['version']),$module.' ambiguous result cannot be edited into a retry');
    denied(fn()=>$workspace->schedule($draft['id'],$now,$uncertain['version']),$module.' ambiguous result cannot be rescheduled');
    check($workspace->runDue($now)['prepared'] === 0,$module.' ambiguous queue never retried');
    check(count($workspace->list()) === 1 && $other->list() === [],$module.' listing does not leak other club');
    $template = $workspace->saveTemplate(['name'=>'Eigene Vorlage','title'=>'Neuer Titel','body'=>'Vorlagentext']);
    $fromTemplate = $workspace->fromTemplate($template['id']);
    $workspace->saveTemplate(['id'=>$template['id'],'name'=>'Neue Vorlage','body'=>'Verändert'],1);
    check($workspace->get($fromTemplate['id'])['template_snapshot']['body'] === 'Vorlagentext',$module.' template snapshot survives later template edits');
    check(count($workspace->templateRevisions($template['id'])) === 2,$module.' template history available');
    $richTemplate = $workspace->saveTemplate(['name'=>'Formatierte Vorlage','title'=>'Emoji und Tabelle','body_html'=>'<p><strong>Grüße 🎶</strong></p><table><tr><td style="background-color:#abc">Kultur</td></tr></table><script>bad()</script>']);
    check(str_contains($richTemplate['body_html'],'<strong>') && !str_contains($richTemplate['body_html'],'script') && str_contains($richTemplate['body'],'Grüße 🎶'),$module.' template preserves sanitized richtext and derives Unicode plain body');
    $richDraft = $workspace->fromTemplate($richTemplate['id']);
    check((new Workspace($db,$admin,$module))->get($richDraft['id'])['body_html'] === $richTemplate['body_html'],$module.' formatted template survives new-service persistence roundtrip');
    $workspace->saveTemplate(['id'=>$richTemplate['id'],'name'=>'Neue Formatierung','body_html'=>'<h2>Anderer Text</h2>'],1);
    check($workspace->get($richDraft['id'])['template_snapshot']['body_html'] === $richTemplate['body_html'],$module.' rich template snapshot remains immutable after source edit');
    $plainFromTemplate = $workspace->fromTemplate($richTemplate['id'],['body'=>'Expliziter Klartext überschreibt formatierte Vorlage']);
    check($plainFromTemplate['body'] === 'Expliziter Klartext überschreibt formatierte Vorlage' && empty($plainFromTemplate['body_html']),$module.' legacy plain body override replaces template rich body');
    $richDraft = $workspace->save(['id'=>$richDraft['id'],'title'=>'Titel geändert'],$richDraft['version']);
    check(str_contains($richDraft['body_html'],'<table>'),$module.' title-only edit preserves existing rich body');
    $richDraft = $workspace->save(['id'=>$richDraft['id'],'body'=>'Legacy-Klartextänderung mit Emoji 🎶'],$richDraft['version']);
    check($richDraft['body'] === 'Legacy-Klartextänderung mit Emoji 🎶' && empty($richDraft['body_html']),$module.' explicit legacy plain edit replaces stale rich body');
    $cleared = $workspace->save(['id'=>$richDraft['id'],'body_html'=>''],$richDraft['version']);
    check($cleared['body'] === '' && empty($cleared['body_html']),$module.' clearing richtext does not restore stale plain body');
    denied(fn()=>$edit->saveTemplate(['name'=>'Nicht erlaubt']),$module.' template management requires manage');
    denied(fn()=>$workspace->saveTemplate(['id'=>$template['id'],'name'=>'Veraltet'],1),$module.' stale template rejected');
    $automationSource = $workspace->approve($fromTemplate['id'],$fromTemplate['version']);
    $plan = $workspace->planAutomation($automationSource['id'],$now,$automationSource['version']);
    $workspace->pauseAutomation($plan['id'],1,true);
    check($workspace->runDue($now)['drafts_prepared'] === 0,$module.' paused automation stays paused');
    denied(fn()=>$workspace->pauseAutomation($plan['id'],1,false),$module.' stale automation toggle rejected');
    $workspace->pauseAutomation($plan['id'],2,false);
    check($workspace->runDue($now)['drafts_prepared'] === 1,$module.' due automation creates draft');
    check($workspace->runDue($now)['drafts_prepared'] === 0,$module.' automation restart does not duplicate');
    $automated = array_values(array_filter($workspace->list(),fn($r)=>($r['automation_id']??'') === $plan['id']));
    check(count($automated) === 1 && $automated[0]['status'] === 'draft' && $automated[0]['approved_by'] === null,$module.' automation draft requires fresh human approval');
}

$social = new Workspace($db,$admin,'social');
$story = $social->save(['title'=>'Story','kind'=>'story','body'=>'Test','story'=>[['title'=>'Erste Folie','text'=>'Termin merken']]]);
$nextStory = $social->save(['id'=>$story['id'],'story'=>[['title'=>'Zweite Folie','text'=>'Details']]],1);
check($social->revisions($story['id'])[1]['payload']['story'][0]['title'] === 'Erste Folie','story slide history preserved');
denied(fn()=>$social->save(['title'=>'Bad','kind'=>'story','story'=>[['title'=>'Bad','media_ref'=>'https://remote.invalid/image']]]),'story remote image reference rejected');
denied(fn()=>(new Workspace($db,$admin,'newsletter'))->get($story['id']),'module repositories are isolated');
denied(fn()=>new Workspace($db,$admin,'website'),'website cannot use communication repository');
$unsafe = $social->save(['title'=>'<script>alert(1)</script>','body'=>'<img src=x onerror=alert(1)>','blocks'=>[['type'=>'heading','text'=>'<svg onload=alert(1)>'],['type'=>'button','text'=>'Link','url'=>'https://example.invalid/']]]);
$preview = $social->preview($unsafe['id']);
check(!str_contains($preview,'<script') && !str_contains($preview,'<img') && !str_contains($preview,'<svg') && str_contains($preview,'&lt;script&gt;'),'preview escapes editor text and does not fetch remote images');
denied(fn()=>$social->save(['title'=>'Bad','body'=>'Test','blocks'=>[['type'=>'button','text'=>'Bad','url'=>'javascript:alert(1)']]]),'unsafe button scheme rejected');
denied(fn()=>Content::time('2026-02-30T12:00:00Z'),'nonexistent calendar date rejected');

$subscribers = new Subscribers($db,$admin);
$captured = '';
$subscriber = $subscribers->requestConsent(' Person@EXAMPLE.INVALID ','Person',['events'],'Synthetische Anmeldung',static function ($value) use (&$captured): void { $captured = $value; });
check($subscriber['email'] === 'person@example.invalid' && $subscriber['status'] === 'pending','subscription normalizes email and remains pending');
check($captured !== '' && !str_contains(json_encode($subscriber,JSON_THROW_ON_ERROR),$captured),'DOI token never appears in returned subscriber');
$token1 = $captured;
$subscriber2 = $subscribers->requestConsent('person@example.invalid','Person',['events'],'Erneute synthetische Anmeldung',static function ($value) use (&$captured): void { $captured = $value; });
check($subscriber2['id'] === $subscriber['id'] && count($subscribers->list()) === 1,'case-insensitive duplicate subscription persists one recipient');
check(!$subscribers->confirm($token1),'replaced DOI token cannot confirm');
check((new Subscribers($db,$outsider))->confirm($captured) === false,'DOI token bound to correct club');
check($subscribers->confirm($captured),'valid DOI confirms persisted recipient');
check(!$subscribers->confirm($captured),'DOI is single use');
check(count($subscribers->eligible('events')) === 1 && count($subscribers->eligible('news')) === 0 && count($subscribers->eligible('all')) === 0,'newsletter purpose filtering does not broaden consent');
check(!str_contains(json_encode($subscribers->history($subscriber['id']),JSON_THROW_ON_ERROR),$captured),'consent audit contains no raw token');
$stored = Db::run($db,'SELECT token_hash FROM khn_newsletter_tokens WHERE club_id=?',[$admin->club()])->fetchAll(PDO::FETCH_COLUMN);
check(!in_array($captured,$stored,true) && in_array(hash('sha256',$captured),$stored,true),'database persists only hashed tokens');
$unsubscribe = '';
$subscribers->issueUnsubscribe($subscriber['id'],static function ($value) use (&$unsubscribe): void { $unsubscribe = $value; });
$newsletter = new Workspace($db,$admin,'newsletter');
$edition = $newsletter->save(['title'=>'Termine','body'=>'Synthetische Termine','audience'=>'events']);
$edition = $newsletter->approve($edition['id'],1);
$edition = $newsletter->schedule($edition['id'],$now,$edition['version']);
check(array_values(array_filter($newsletter->queue(),fn($j)=>$j['item_id'] === $edition['id']))[0]['recipient_count'] === 1,'newsletter schedule snapshots confirmed audience');
check($subscribers->unsubscribe($unsubscribe) && $subscribers->unsubscribe($unsubscribe),'unsubscribe persists and is idempotent');
check($subscribers->eligible('events') === [],'unsubscribed recipient suppressed');
$newsletter->runDue($now);
check(array_values(array_filter($newsletter->queue(),fn($j)=>$j['item_id'] === $edition['id']))[0]['recipient_count'] === 0,'queue rechecks revoked consent before local preparation');
denied(fn()=>(new Subscribers($db,$reader))->list(),'reader cannot inspect newsletter recipients');
denied(fn()=>(new Subscribers($db,$editor))->recordWrittenConsent('x@example.invalid','X',['news'],'Synthetischer Nachweis'),'written consent requires management authority');
denied(fn()=>$subscribers->recordWrittenConsent('x@example.invalid','X',['news'],'Ja'),'written consent requires meaningful evidence');
$written = $subscribers->recordWrittenConsent('written@example.invalid','Schriftlich',['events','news'],'Synthetischer Papierbeleg DEMO-001');
check($written['status'] === 'confirmed' && count($subscribers->eligible('all')) === 1,'documented written consent supports combined editions');
$expired = '';
$exp = $subscribers->requestConsent('expired@example.invalid','Abgelaufen',['news'],'Synthetisch',static function ($value) use (&$expired): void { $expired = $value; });
Db::run($db,'UPDATE khn_newsletter_tokens SET expires_at=? WHERE club_id=? AND subscriber_id=?',['2000-01-01 00:00:00',$admin->club(),$exp['id']]);
check(!$subscribers->confirm($expired) && $subscribers->get($exp['id'])['status'] === 'pending','expired DOI does not activate recipient');

$contacts = new PressContacts($db,$admin);
$contact = $contacts->save(['email'=>'PRESS@example.invalid','name'=>'Testredaktion','outlet'=>'Synthetische Zeitung']);
denied(fn()=>$contacts->save(['email'=>' press@example.invalid ','name'=>'Doppelt']),'press address deduplication blocks duplicate recipient');
denied(fn()=>(new PressContacts($db,$outsider))->save(['id'=>$contact['id'],'email'=>'press@example.invalid','name'=>'Andere Redaktion'],1),'press contact cross-club change rejected');
$press = new Workspace($db,$admin,'press');
$release = $press->save(['title'=>'Freie Mitteilung','body'=>'Testtext','recipient_ids'=>[$contact['id'],$contact['id']]]);
check(count($release['recipient_ids']) === 1,'press campaign deduplicates selected contacts');
$release = $press->approve($release['id'],1);
$release = $press->schedule($release['id'],$now,$release['version']);
$contacts->save(['id'=>$contact['id'],'email'=>'press@example.invalid','name'=>'Testredaktion','outlet'=>'Synthetische Zeitung','active'=>false],1);
$press->runDue($now);
check(array_values(array_filter($press->queue(),fn($j)=>$j['item_id'] === $release['id']))[0]['recipient_count'] === 0,'deactivated press contact suppressed before local preparation');
check((new PressContacts($db,$outsider))->list() === [],'press contact listing tenant isolated');
Db::run($db,'UPDATE native_users SET active=0 WHERE id=?',['editor']);
denied(fn()=>(new Workspace($db,$editor,'social'))->get($story['id']),'disabled user immediately loses access');
Db::run($db,'UPDATE native_users SET active=1 WHERE id=?',['editor']);

// Two independent PDO processes wait on the same item row and race the same version.
// The loser must observe the committed current row, not a pre-lock consistent snapshot.
$race = $social->save(['title'=>'Parallelfassung','body'=>'Original']);
$db->beginTransaction();
Db::run($db,'SELECT id FROM khn_social_items WHERE club_id=? AND id=? FOR UPDATE',[$admin->club(),$race['id']]);
$workers = [];
for ($i=0; $i<2; ++$i) {
    $pipes = [];
    $process = proc_open([...TestSupport::phpCommand(),__FILE__,'--race-save',$race['id'],'1',(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Paralleltest konnte nicht starten.');
    fclose($pipes[0]);
    if (trim((string)fgets($pipes[1])) !== 'ready') throw new RuntimeException('Paralleltest nicht bereit.');
    $workers[] = [$process,$pipes];
}
$db->commit();
$outcomes = [];
foreach ($workers as [$process,$pipes]) {
    $outcomes[] = trim(stream_get_contents($pipes[1]));
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0 && $error === '','concurrent editor process completes without database failure');
}
sort($outcomes);
check($outcomes === ['conflict','saved'],'concurrent editors produce exactly one save and one version conflict');
check($social->get($race['id'])['version'] === 2 && count($social->revisions($race['id'])) === 2,'losing concurrent edit leaves no extra version');

echo "$checks native communication checks passed; synthetic isolated MySQL; no mail, HTTP or external publication.\n";
