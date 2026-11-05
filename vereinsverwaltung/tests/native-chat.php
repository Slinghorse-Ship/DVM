<?php declare(strict_types=1);
require dirname(__DIR__) . '/native/bootstrap.php';

use Kulturhus\Native\Chat\{Conflict,Polls,Schema,Workspace};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\TestSupport;

// Each process connects only through the guarded isolated MySQL test configuration.
if (str_starts_with($argv[1] ?? '', '--race-')) {
    $db = TestSupport::db();
    $chat = new Workspace($db, new Access($db, 'admin'));
    $polls = new Polls($db, new Access($db, 'admin'));
    while (microtime(true) < (float)$argv[6]) usleep(1000);
    try {
        match ($argv[1]) {
            '--race-edit' => $chat->edit($argv[2], (int)$argv[3], $argv[4], 1),
            '--race-send' => $chat->send($argv[2], $argv[4], $argv[5]),
            '--race-vote' => $polls->vote($argv[2], $argv[3], (int)$argv[4], 0, $argv[5]),
            '--race-poll' => $polls->create($argv[2], $argv[4], ['Ja','Nein'], null, $argv[5]),
            default => throw new RuntimeException('Unbekannter Testmodus.'),
        };
        echo 'ok';
    }
    catch (Conflict) { echo 'conflict'; }
    exit;
}

$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException('FEHLER: ' . $label);
    $count++;
};
$denied = static function (callable $operation, string $label) use ($check): void {
    try { $operation(); } catch (DomainException) { $check(true, $label); return; }
    $check(false, $label);
};
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
Schema::install($db);
$admin = new Workspace($db, new Access($db, 'admin'));
$editor = new Workspace($db, new Access($db, 'editor'));
$reader = new Workspace($db, new Access($db, 'reader'));
$outsider = new Workspace($db, new Access($db, 'outsider'));
$polls = new Polls($db, new Access($db, 'admin'));
$editorPolls = new Polls($db, new Access($db, 'editor'));
$readerPolls = new Polls($db, new Access($db, 'reader'));
$key = static fn(): string => bin2hex(random_bytes(16));
$check($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'MySQL');
$check(array_column($admin->directory(), 'id') === ['admin','editor','reader'], 'Vereinsverzeichnis');
$denied(fn() => $admin->create('Fremder Verein', ['outsider']), 'Keine Fremdeinladung');
$denied(fn() => $reader->create('Nur Lesen', ['admin']), 'Leser erstellt keine Unterhaltung');
$denied(fn() => $admin->create('Allein', []), 'Mindestens zwei');
$denied(fn() => $admin->create("Titel\0", ['editor']), 'Steuerzeichen');
$thread = $admin->create('Synthetischer Chat ' . $key(), ['editor','editor']);
$id = $thread['id'];
$check(count($admin->people($id)) === 2, 'Teilnehmer dedupliziert');
$denied(fn() => $reader->get($id), 'Nichtmitglied ausgeschlossen');
$denied(fn() => $outsider->get($id), 'Fremder Verein ausgeschlossen');
$denied(fn() => $editor->addPeople($id, ['reader']), 'Nur Eigentümer oder Verwaltung lädt ein');
$admin->addPeople($id, ['reader']);
$check(count($reader->people($id)) === 3, 'Einladung');
$denied(fn() => $reader->send($id, 'Unzulässig', $key()), 'Leser schreibt nicht');
$sendKey = $key();
$first = $admin->send($id, 'Hallo <script>alert(1)</script>', $sendKey);
$duplicate = $admin->send($id, 'Hallo <script>alert(1)</script>', $sendKey);
$check($first['seq'] === $duplicate['seq'], 'Idempotentes Senden');
$check(count($reader->messages($id)) === 1, 'Kein Nachrichtenduplikat');
$check($reader->messages($id)[0]['body'] === 'Hallo <script>alert(1)</script>', 'Klartext unverändert; UI muss escapen');
$denied(fn() => $admin->send($id, 'Anderer Text', $sendKey), 'Konfligierende Wiederholung');
$denied(fn() => $admin->send($id, '', $key()), 'Leere Nachricht');
$denied(fn() => $admin->send($id, 'x', 'bad'), 'Anfragekennung validiert');
$denied(fn() => $editor->edit($id, (int)$first['seq'], 'Fremdänderung', 1), 'Nur eigene Nachricht');
$edited = $admin->edit($id, (int)$first['seq'], 'Korrigiert', 1);
$check((int)$edited['version'] === 2, 'Version steigt');
$denied(fn() => $admin->edit($id, (int)$first['seq'], 'Veraltet', 1), 'Versionskonflikt');
$check(count($admin->revisions($id, (int)$first['seq'])) === 2, 'Versionen aufbewahrt');
$check($admin->send($id, 'Hallo <script>alert(1)</script>', $sendKey)['body'] === 'Korrigiert', 'Späte Wiederholung überschreibt Bearbeitung nicht');
$reader->markRead($id, PHP_INT_MAX);
$check((int)$reader->get($id)['last_read'] === (int)$first['seq'], 'Lesestand auf tatsächliches Ende begrenzt');
$reader->markRead($id, 0);
$check((int)$reader->get($id)['last_read'] === (int)$first['seq'], 'Lesestand monoton');
$next = $editor->send($id, 'Zweite Nachricht', $key());
$check(count($reader->messages($id, (int)$first['seq'])) === 1, 'Vorwärtspaginierung');
$listed = array_values(array_filter($reader->list(), fn($t) => $t['id'] === $id));
$check((int)$listed[0]['unread'] === 1, 'Ungelesene Nachrichten');
$other = $admin->create('Zweite Unterhaltung', ['editor']);
$denied(fn() => $admin->edit($other['id'], (int)$next['seq'], 'Falsche Unterhaltung', 1), 'Nachricht an Unterhaltung gebunden');
$pollKey = $key();
$poll = $polls->create($id, 'Welcher Tag?', ['Montag','Dienstag'], null, $pollKey);
$check(count($poll['options']) === 2 && $poll['open'], 'Umfrage');
$check($polls->create($id, 'Welcher Tag?', ['Montag','Dienstag'], null, $pollKey)['id'] === $poll['id'], 'Umfrage idempotent');
$denied(fn() => $polls->create($id, 'Doppelt?', ['Ja',' ja '], null, $key()), 'Doppelte Optionen');
$denied(fn() => $polls->create($id, 'Vergangenheit?', ['Ja','Nein'], '2000-01-01T00:00:00Z', $key()), 'Vergangene Frist');
$denied(fn() => $polls->create($id, 'Ungültig?', ['Ja','Nein'], '2027-02-30T00:00:00Z', $key()), 'Ungültiges Datum');
$denied(fn() => $admin->edit($id, (int)$poll['message_seq'], 'Neue Frage', 1), 'Umfrage unveränderlich');
$denied(fn() => $admin->send($id, 'Welcher Tag?', $pollKey), 'Umfrage-/Nachrichtenkennung getrennt');
$denied(fn() => (new Polls($db, new Access($db, 'outsider')))->get($id, $poll['id']), 'Umfrage vereinsgebunden');
$denied(fn() => $polls->get($other['id'], $poll['id']), 'Umfrage unterhaltungsgebunden');
$denied(fn() => $readerPolls->vote($id, $poll['id'], 1, 0, $key()), 'Leser stimmt nicht ab');
$voteKey = $key();
$vote = $editorPolls->vote($id, $poll['id'], 1, 0, $voteKey);
$check($vote['total_votes'] === 1 && $vote['vote_version'] === 1, 'Eine Stimme');
$check($editorPolls->vote($id, $poll['id'], 1, 0, $voteKey)['total_votes'] === 1, 'Stimme idempotent');
$denied(fn() => $editorPolls->vote($id, $poll['id'], 2, 0, $key()), 'Stimmversion');
$denied(fn() => $editorPolls->vote($id, $poll['id'], 99, 1, $key()), 'Unbekannte Option');
$changed = $editorPolls->vote($id, $poll['id'], 2, 1, $key());
$check($changed['own_option'] === 2 && $changed['vote_version'] === 2 && $changed['total_votes'] === 1, 'Stimme ändern');
$check($editorPolls->vote($id, $poll['id'], 1, 0, $voteKey)['own_option'] === 2, 'Alte Wiederholung rollt Stimme nicht zurück');
$denied(fn() => $editorPolls->close($id, $poll['id'], 1), 'Fremde Umfrage nicht schließen');
$closed = $polls->close($id, $poll['id'], 1);
$check(!$closed['open'] && (int)$closed['version'] === 2, 'Schließen');
$denied(fn() => $polls->close($id, $poll['id'], 1), 'Schließversion');
$denied(fn() => $editorPolls->vote($id, $poll['id'], 1, 2, $key()), 'Geschlossen keine neue Stimme');
$check($editorPolls->vote($id, $poll['id'], 1, 0, $voteKey)['own_option'] === 2, 'Bekannte Wiederholung nach Schließen');
$reader->leave($id);
$denied(fn() => $reader->messages($id), 'Verlassene Unterhaltung gesperrt');
$denied(fn() => $reader->revisions($id, (int)$first['seq']), 'Versionen nach Austritt gesperrt');
$admin->leave($id);
$check($editor->get($id)['owner_id'] === 'editor', 'Eigentum übergeben');
$denied(fn() => $admin->get($id), 'Auch Verwaltung ohne Mitgliedschaft ausgeschlossen');
$editor->addPeople($id, ['reader']);
$check(count($editor->people($id)) === 2, 'Nachfolger darf einladen');
$denied(fn() => $editor->leave($id), 'Keinen schreibgeschützten Besitzer hinterlassen');
Db::run($db, 'UPDATE native_users SET active=0 WHERE id=?', ['editor']);
try { $denied(fn() => $editor->messages($id), 'Deaktivierung sofort wirksam'); }
finally { Db::run($db, 'UPDATE native_users SET active=1 WHERE id=?', ['editor']); }

// Two independent PHP/PDO processes start at one shared instant.
$parallel = static function (string $mode, string $thread, string $object, array $bodies, array $keys) use ($check): array {
    $start = sprintf('%.6f', microtime(true) + 0.7);
    $children = [];
    foreach ($bodies as $index => $body) {
        $pipes = [];
        $process = proc_open([...TestSupport::phpCommand(),__FILE__,$mode,$thread,$object,$body,$keys[$index],$start], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Paralleltest konnte nicht starten.');
        fclose($pipes[0]);
        $children[] = [$process,$pipes];
    }
    $results = [];
    foreach ($children as [$process,$pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $errors !== '') throw new RuntimeException('Parallelprozess fehlgeschlagen: ' . $errors . ' / ' . end($results));
        $check(true, 'Parallelprozess erfolgreich');
    }
    sort($results);
    return $results;
};
$race = $admin->send($other['id'], 'Ausgangsversion', $key());
$results = $parallel('--race-edit', $other['id'], (string)$race['seq'], ['Rennen A','Rennen B'], [$key(),$key()]);
$check($results === ['conflict','ok'], 'Genau eine konkurrierende Bearbeitung erfolgreich');
$check(count($admin->revisions($other['id'], (int)$race['seq'])) === 2, 'Keine konkurrierende Überschreibung');
$sendRaceKey = $key();
$beforeSend = count($admin->messages($other['id']));
$results = $parallel('--race-send', $other['id'], '', ['Wiederholung','Wiederholung'], [$sendRaceKey,$sendRaceKey]);
$check($results === ['ok','ok'] && count($admin->messages($other['id'])) === $beforeSend+1, 'Paralleles Senden genau einmal');
$voteRace = $polls->create($other['id'], 'Parallele Stimme', ['Ja','Nein'], null, $key());
$results = $parallel('--race-vote', $other['id'], $voteRace['id'], ['1','2'], [$key(),$key()]);
$check($results === ['conflict','ok'] && $polls->get($other['id'], $voteRace['id'])['total_votes'] === 1, 'Parallele Stimme mit Versionskonflikt');
$pollRaceKey = $key();
$beforePoll = count($admin->messages($other['id']));
$results = $parallel('--race-poll', $other['id'], '', ['Parallele Frage','Parallele Frage'], [$pollRaceKey,$pollRaceKey]);
$check($results === ['ok','ok'] && count($admin->messages($other['id'])) === $beforePoll+1, 'Parallele Umfrage genau einmal');
echo json_encode(['suite'=>'native-chat','passed'=>$count,'database'=>'isolated-local-mysql','parallel_cases'=>['edit','message retry','vote','poll retry']], JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT) . PHP_EOL;
