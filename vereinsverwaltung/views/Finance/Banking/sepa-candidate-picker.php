<?php declare(strict_types=1);
$candidateMove=input($_POST,'action')==='sepa-order-selection'?($_POST['candidate_move']??''):'';
$candidateList=$sepaService->candidatePage($_POST['candidate_page']??1,$_POST['candidate_search']??'',$candidateMove);
$candidateSelection=$sepaService->selection($_POST['entries']??[]);$visibleIds=array_column($candidateList['rows'],'id');
?>
<fieldset id="sepa-candidate-picker">
<legend>Offene Beitragsrechnungen auswählen</legend>
<label>Rechnung oder Mitglied suchen<input class="form-control" type="search" name="candidate_search" maxlength="200" value="<?= e($candidateList['search']) ?>"></label>
<button class="btn btn-outline theme-secondary" name="candidate_move" value="search" formnovalidate>Rechnungen suchen</button>
<p data-sepa-candidate-summary><?= (int)$candidateList['total'] ?> Rechnungen · Seite <?= (int)$candidateList['page'] ?> von <?= (int)$candidateList['pages'] ?> · <?= count($candidateSelection) ?> ausgewählt</p>
<?php foreach($candidateSelection as$id)if(!in_array($id,$visibleIds,true))echo hidden('entries[]',$id); ?>
<div class="sepa-candidate-cards"><?php foreach($candidateList['rows']as$entry): ?><label data-sepa-candidate="<?= e($entry['id']) ?>"><input class="check" type="checkbox" name="entries[]" value="<?= e($entry['id']) ?>" <?= in_array($entry['id'],$candidateSelection,true)?'checked':'' ?>><span><?= e($entry['counterparty'].' · '.$entry['display_number'].' · '.'Noch einzuziehen: '.euro($entry['remaining_cents']).((int)$entry['allocated_cents']>0?' · Ursprünglich: '.euro($entry['amount_cents']).' · Bereits bezahlt: '.euro($entry['allocated_cents']):'')) ?></span></label><?php endforeach ?>
<?php if(!$candidateList['rows']): ?><p>Keine Rechnungen gefunden.</p><?php endif ?></div>
<div class="actions" aria-label="Rechnungsseiten">
<button class="btn btn-outline theme-secondary" name="candidate_move" value="all" formnovalidate>Alle auf dieser Seite auswählen</button>
<?php if($candidateList['page']>1): ?><button class="btn btn-outline theme-secondary" name="candidate_move" value="previous" formnovalidate>Vorherige Rechnungen</button><?php endif ?>
<?php if($candidateList['page']<$candidateList['pages']): ?><button class="btn btn-outline theme-secondary" name="candidate_move" value="next" formnovalidate>Nächste Rechnungen</button><?php endif ?>
<label>Rechnungsseite<input class="form-control" type="number" name="candidate_page" min="1" max="<?= (int)$candidateList['pages'] ?>" value="<?= (int)$candidateList['page'] ?>"></label>
<button class="btn btn-outline theme-secondary" formnovalidate>Rechnungsseite anzeigen</button>
<button class="btn btn-outline theme-secondary" name="candidate_move" value="clear" formnovalidate>Gesamte Auswahl leeren</button></div>
</fieldset>
<button class="btn btn-solid theme-primary" name="action" value="sepa-order-preview">Auswahl prüfen</button>
