<?php declare(strict_types=1);
$tasks=[];
if($canWrite)$tasks[]=['Mitglied aufnehmen',['view'=>'members','new'=>1,'create_as'=>'member']];
if($finance->canWrite())$tasks[]=['Zahlungen zuordnen',['view'=>'finance','tab'=>'bank','assignment'=>'open']];
if($documents->canWrite())$tasks[]=['Dokumente ablegen',['view'=>'documents','new_document'=>1]];
if($canCalendar&&$canWrite)$tasks[]=['Termin planen',['view'=>'calendar','new_event'=>1]];
if($workspaceAccess->allows(['view'=>'messages']))$tasks[]=['Sendungen öffnen',['view'=>'messages']];
if($canWebsite)$tasks[]=['Website bearbeiten',['view'=>'website']];
if($tasks): ?>
<section class="card"><h2>Was möchtest du erledigen?</h2><div class="actions"><?php foreach($tasks as [$label,$route]): ?><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url($route)) ?>"><?= e($label) ?></a><?php endforeach ?></div></section>
<?php endif ?>
