<?php declare(strict_types=1);if(!$fintsChoiceForm['posted'])return; ?>
<details class="fints-choice-original" <?= $fintsChoiceForm['unsupported']?'open':'' ?>><summary><?= e($fintsText('Bankauswahl prüfen')) ?></summary>
<textarea readonly data-fints-choice-original aria-label="<?= e($fintsText('Bankauswahl prüfen')) ?>"><?= e(\Kulturhus\Verein\Web\BankDialogFields::original($fintsChoiceForm)) ?></textarea>
<?php if($fintsChoiceForm['unsupported']): ?><p class="error" role="alert"><?= e($fintsText('Diese Eingabeform wird nicht übernommen. Öffne die Bankauswahl bewusst neu.')) ?></p><a class="btn btn-outline theme-secondary" href="<?= e(url(bankListContext($_GET)+['fints'=>'1']+($paymentMode?['payment'=>$paymentKind,'payment_order'=>$paymentOrderId]:[]))) ?>"><?= e($fintsText('Bankauswahl neu öffnen')) ?></a><?php endif ?>
</details>
