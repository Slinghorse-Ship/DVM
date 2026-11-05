<?php
$songIndex=input($_GET,'song','new');
$songPosted=input($_POST,'action')==='gema-song'&&input($_POST,'operation')==='save';
if($songPosted){$songIndex=input($_POST,'song_index','new');$songFields=scalarFields($_POST,array_keys(\Kulturhus\Verein\GemaSetlist::FIELDS));}
else $songFields=$songIndex!=='new'&&ctype_digit($songIndex)?($gema['songs'][(int)$songIndex]??[]):[];
$gemaRoute=['view'=>'gema','event'=>$event['id'],'year'=>input($_GET,'year',substr(localDateTime($event['starts_at']),0,4))];
$missingSongs=[];foreach($gema['songs'] as $i=>$song){$missing=[];if($song['B']==='')$missing[]='Liedtitel';if($song['F']==='')$missing[]='Komponist oder Interpret';if($missing)$missingSongs[]=$gemaFormat('Titel {number}: {fields} ergänzen.',['number'=>$i+1,'fields'=>implode(' '.$gemaText('und').' ',array_map($gemaText,$missing))]);}
$canExport=count($gema['songs'])>0&&!$missingSongs;
$help=[
 'A'=>'Kennung aus der GEMA-Werksuche. Nur eintragen, wenn bekannt; sonst leer lassen.',
 'B'=>'Name des tatsächlich gespielten Stücks. Aus der Liste der Band übernehmen, einschließlich Zugaben.',
 'C'=>'Zusatz zum Titel, etwa der Satz eines mehrteiligen Werks. Nur übernehmen, wenn die Musikliste ihn nennt.',
 'D'=>'Zahl der beteiligten Musiker und Sänger. Bei der Band erfragen; besonders bei klassischer Musik können solche Zusatzangaben verlangt werden.',
 'E'=>'Tatsächlich gespielte Minuten und Sekunden, zum Beispiel 03:45. Bei den Musikern erfragen; bei Bühnenaufführungen und klassischer Musik die GEMA-Vorgaben beachten.',
 'F'=>'Bei einer Person den Nachnamen hier und den Vornamen im nächsten Feld eintragen; bei einer Band den Bandnamen. Angaben zum Komponisten oder bekannten Interpreten aus der Musikliste übernehmen. Bei Covers die Originalversion nennen, nicht automatisch eure auftretende Band.',
 'G'=>'Vorname der Person aus dem Feld Komponist / Interpret. Bei einem Bandnamen leer lassen.',
 'H'=>'Wer hat den Liedtext geschrieben? Von der Band oder aus der GEMA-Werksuche übernehmen, soweit bekannt.',
 'I'=>'Vorname des Textdichters. Leer lassen, wenn nicht bekannt.',
 'J'=>'Musikverlag aus den Werkangaben, sofern bekannt. Nicht selbst vermuten.',
 'K'=>'Live: von Musikern gespielt oder gesungen. Tonträger: eine Aufnahme abgespielt. Für jedes Stück passend auswählen.',
 'L'=>'Angabe aus der Musikliste oder von GEMA übernehmen. Wenn unbekannt, zunächst leer lassen.',
 'M'=>'Besondere Rechte am Werk. Nur nach Angabe der Rechteinhaber oder von GEMA ausfüllen; wenn unklar, leer lassen und dort klären.',
 'N'=>'Nur bei einem Ausschnitt (Fragment) oder einer Zusammenstellung mehrerer Stücke (Potpourri) ausfüllen. Band nach der passenden Angabe fragen.',
 'O'=>'Internationaler Werkcode aus der GEMA-Werksuche. Optional, wenn bekannt; keine Nummer erfinden.',
 'P'=>'Wer hat die gespielte Fassung bearbeitet? Nur ergänzen, wenn eine Bearbeitung und der Name bekannt sind.',
 'Q'=>'Vorname des Bearbeiters, soweit bekannt.',
 'R'=>'Weiterer Komponist aus den Werkangaben, soweit bekannt.',
 'S'=>'Vorname des weiteren Komponisten, soweit bekannt.',
 'T'=>'Weiterer Textdichter aus den Werkangaben, soweit bekannt.',
 'U'=>'Vorname des weiteren Textdichters, soweit bekannt.',
 'V'=>'Dritter Textdichter aus den Werkangaben, soweit bekannt.',
 'W'=>'Vorname des dritten Textdichters, soweit bekannt.',
 'X'=>'Weitere an diesem Werk beteiligte Person. Nur aus vorhandenen Werkangaben übernehmen.',
 'Y'=>'Vorname dieser weiteren Person, soweit bekannt.',
];
$labels=array_replace(\Kulturhus\Verein\GemaSetlist::FIELDS,['A'=>'GEMA-Werknummer (falls bekannt)','B'=>'Liedtitel','F'=>'Komponist / Interpret: Nachname oder Bandname','G'=>'Dazugehöriger Vorname (falls eine Person)','K'=>'Wie wurde dieses Stück gespielt?','O'=>'ISWC – internationaler Werkcode (falls bekannt)']);
?>
<div class="content" id="gema-music" data-gema-interface><section class="card"><h2><?= e($gemaText('2. Musikliste (Setlist)')) ?></h2>
<p><?= e($gemaText('Eine Setlist ist die Liste der tatsächlich gespielten Stücke. Die Angaben kommen von der Band oder den Musikern – du musst Werknummern und Urheber nicht aus dem Kopf kennen.')) ?></p>
<h3><?= e($gemaText('Einfachster Weg: Die Band reicht die Liste ein')) ?></h3>
<ol><li><?= e($gemaText('Die Veranstaltung zuerst im GEMA-Portal anmelden.')) ?></li><li><?= e($gemaText('Dort unter „Meine Veranstaltungen“ den Link zur Setlist-Meldung erstellen und an die Band oder das Management weitergeben.')) ?></li><li><?= e($gemaText('Nach der Einreichungsbestätigung hier oben die Musikliste als „Erledigt · bei GEMA eingereicht“ vermerken. Den Eingang im Portal kontrollieren; ihr bleibt als Veranstalter verantwortlich.')) ?></li></ol>
<p><a class="btn btn-outline theme-secondary button secondary" href="https://www.gema.de/de/w/hilfe/musiknutzer/musik-nutzen/setlist/setlist-meldung-bands-kunstlerinnen-managements-delegieren" target="_blank" rel="noopener noreferrer"><?= e($gemaText('GEMA-Anleitung: Einreichung an die Band übergeben ↗')) ?></a></p>
<p><?= e($gemaText('Wenn die Band bereits eingereicht hat, musst du die Titel hier nicht noch einmal abschreiben.')) ?></p>
<details><summary><?= e($gemaText('Die Band soll uns die Angaben schicken: Text zum Kopieren')) ?></summary><label><?= e($gemaText('Anfrage an die Band')) ?><textarea class="form-control" readonly rows="6">Hallo, für euren Auftritt „<?= e($event['title']) ?>“ am <?= e(dateDe(substr(localDateTime($event['starts_at']),0,10))) ?> benötigen wir die tatsächlich gespielte Musikliste einschließlich Zugaben. Bitte nennt je Stück den Titel und die Komponisten beziehungsweise Originalinterpreten; wenn vorhanden auch Werknummer und gespielte Dauer. Ihr könnt die Setlist alternativ über den Veranstaltungslink selbst bei GEMA einreichen. Gebt uns dann bitte Bescheid, sobald die Einreichung bestätigt ist. Vielen Dank!</textarea></label><p><?= e($gemaText('Den Text kopieren und selbst weitergeben. Hier wird keine Nachricht versendet.')) ?></p></details>
<p><?= e($gemaText('Für Liveveranstaltungen nennt GEMA grundsätzlich sechs Wochen nach dem Auftritt: hier')) ?> <strong><?= e(dateDe($deadlines['setlist_due'])) ?></strong><?= e($gemaText('. Die konkrete Frist im GEMA-Vorgang beachten.')) ?> <a href="https://www.gema.de/de/w/hilfe/musiknutzer/musik-nutzen/setlist/wann-einreichung-pflicht" target="_blank" rel="noopener noreferrer"><?= e($gemaText('GEMA-Hinweise zu Pflicht und Frist ↗')) ?></a></p>
<details <?= $gema['songs']||$songPosted||$songIndex!=='new'?'open':'' ?>><summary><?= e($gemaFormat('Musikliste hier selbst vorbereiten · {count} Titel gespeichert',['count'=>count($gema['songs'])])) ?></summary>
<p><?= e($gemaText('Übernimm die Angaben aus der Musikliste der Band. Fehlendes zunächst leer lassen und als Entwurf speichern. Bekannte Werke kannst du in der')) ?> <a href="https://portal.gema.de/app/repertoiresuche" target="_blank" rel="noopener noreferrer"><?= e($gemaText('GEMA-Werksuche nachschlagen ↗')) ?></a><?= e($gemaText('. Zugaben und Wiederholungen jeweils als eigenen Titel aufnehmen.')) ?></p>
<?php foreach($gema['songs'] as $songNumber=>$savedSong): ?>
<div class="card"><strong><?= e(($songNumber+1).'. '.($savedSong['B']!==''?$savedSong['B']:$gemaText('Liedtitel noch offen'))) ?></strong><p><?= e($savedSong['F'].($savedSong['E']?' · '.$savedSong['E']:'')) ?></p>
<a href="<?= e(url(array_replace($gemaRoute,['song'=>$songNumber])).'#gema-song-editor') ?>"><?= e($gemaText('Titel bearbeiten')) ?></a>
<form method="post"><?= csrf().hidden('action','gema-song').hidden('id',$event['id']).hidden('version',$gema['version']).hidden('song_index',$songNumber) ?>
<?php if($songNumber>0): ?><button class="btn btn-solid theme-primary" name="operation" value="up" aria-label="<?= e($gemaFormat('Titel {number} nach oben',['number'=>$songNumber+1])) ?>"><?= e($gemaText('↑ Nach oben')) ?></button><?php endif ?>
<?php if($songNumber<count($gema['songs'])-1): ?><button class="btn btn-solid theme-primary" name="operation" value="down" aria-label="<?= e($gemaFormat('Titel {number} nach unten',['number'=>$songNumber+1])) ?>"><?= e($gemaText('↓ Nach unten')) ?></button><?php endif ?>
</form><form method="post" data-confirm="<?= e($gemaFormat('Nur Titel {number} aus dieser Musikliste entfernen?',['number'=>$songNumber+1])) ?>"><?= csrf().hidden('action','gema-song').hidden('id',$event['id']).hidden('version',$gema['version']).hidden('song_index',$songNumber) ?><button name="operation" value="remove" class="btn btn-outline theme-secondary secondary"><?= e($gemaText('Titel entfernen')) ?></button></form></div>
<?php endforeach ?>
<h3 id="gema-song-editor"><?= e($gemaText($songIndex==='new'?'Lied hinzufügen':'Lied bearbeiten')) ?></h3>
<form method="post"><?= csrf().hidden('action','gema-song').hidden('operation','save').hidden('id',$event['id']).hidden('version',$songPosted?input($_POST,'version'):(string)$gema['version']).hidden('song_index',$songIndex) ?>
<?php $core=['B','F','G','E','K'];foreach([$core,array_diff(array_keys(\Kulturhus\Verein\GemaSetlist::FIELDS),$core)] as $group=>$fields):if($group): ?><details><summary><?= e($gemaText('Weitere Angaben aus der Musikliste (nur soweit bekannt)')) ?></summary><p><?= e($gemaText('Diese Fachangaben musst du nicht selbst ermitteln. Werte von der Band oder aus der GEMA-Werksuche übernehmen; Unbekanntes leer lassen. Welche Angaben für eure Veranstaltung gebraucht werden, zeigt das GEMA-Portal.')) ?></p><?php endif;foreach($fields as $column): ?>
<label><?= e($gemaText($labels[$column])) ?><?php if(isset(\Kulturhus\Verein\GemaSetlist::CHOICES[$column])): ?><select class="form-control" name="<?= $column ?>" aria-describedby="gema-help-<?= $column ?>"><option value=""><?= e($gemaText('Noch unbekannt / nicht angegeben')) ?></option><?php foreach(\Kulturhus\Verein\GemaSetlist::CHOICES[$column] as $choice): ?><option value="<?= e($choice) ?>" <?= ($songFields[$column]??'')===$choice?'selected':'' ?>><?= e($gemaText($column==='N'?(['F'=>'F · Ausschnitt (Fragment)','P'=>'P · Zusammenstellung (Potpourri)','F/P'=>'F/P · beides'][$choice]):$choice)) ?></option><?php endforeach ?></select><?php else: ?><input class="form-control" name="<?= $column ?>" aria-describedby="gema-help-<?= $column ?>" value="<?= e($songFields[$column]??'') ?>" maxlength="500" <?= $column==='E'?'placeholder="03:45"':'' ?>><?php endif ?></label><p class="muted" id="gema-help-<?= $column ?>"><?= e($gemaText($help[$column])) ?></p>
<?php endforeach;if($group): ?></details><?php endif;endforeach ?>
<p><?= e($gemaText('Als Entwurf darf die Liste unvollständig sein. Für unseren Excel-Download müssen Liedtitel und Komponist / Interpret ausgefüllt sein. Werknummer und weitere unbekannte Angaben blockieren das Vorbereiten nicht; GEMA kann je nach Veranstaltungsart zusätzliche Angaben verlangen.')) ?></p>
<button class="btn btn-solid theme-primary"><?= e($gemaText($songIndex==='new'?'Lied zur Musikliste hinzufügen':'Änderungen am Lied speichern')) ?></button><?php if($songIndex!=='new'): ?> <a href="<?= e(url($gemaRoute).'#gema-music') ?>"><?= e($gemaText('Bearbeitung abbrechen')) ?></a><?php endif ?></form>
<h3><?= e($gemaText('Fertige Liste zu GEMA bringen')) ?></h3>
<?php if($missingSongs): ?><p><?= e($gemaText('Für den Download noch ergänzen:')) ?></p><ul><?php foreach($missingSongs as $missing): ?><li><?= e($missing) ?></li><?php endforeach ?></ul><?php elseif(!$gema['songs']): ?><p><?= e($gemaText('Zuerst die gespielten Titel speichern. Alternativ kann die Band direkt bei GEMA einreichen.')) ?></p><?php endif ?>
<form method="post"><?= csrf().hidden('action','gema-setlist-export').hidden('id',$event['id']) ?><button class="btn btn-solid theme-primary" <?= !$canExport?'disabled':'' ?>><?= e($gemaText('Musikliste als GEMA-Excel herunterladen')) ?></button></form>
<p><?= e($gemaText('Danach im')) ?> <a href="https://portal.gema.de/app/musikfolgen/meinesetlists" target="_blank" rel="noopener noreferrer"><?= e($gemaText('GEMA-Portal ↗')) ?></a> <?= e($gemaText('„Aus Excel laden“ wählen, die Angaben prüfen und dort einreichen. Der Download allein reicht die Liste nicht ein.')) ?></p>
</details></section></div>
