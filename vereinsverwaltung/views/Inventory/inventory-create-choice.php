<?php declare(strict_types=1);
$choiceText=$ivItemText??static fn(string $s):string=>$s;
$quantityMode=input($_GET,'create_mode')==='quantity';
?>
<section class="card iv-create-choice">
 <h2><?= e($choiceText('Wie möchtest du die Gegenstände verwalten?')) ?></h2>
 <p><?= e($choiceText('Wähle, ob du jedes Gerät einzeln unterscheiden möchtest oder vor allem die vorhandene Anzahl brauchst.')) ?></p>
 <div class="iv-create-options">
  <div class="iv-create-option <?= !$quantityMode?'is-selected':'' ?>">
   <a class="btn button <?= $quantityMode?'btn-outline theme-secondary':'btn-solid theme-primary' ?>" data-inventory-mode <?= !$quantityMode?'aria-current="page"':'' ?> aria-describedby="inventory-individual-example" href="<?= e(url(['view'=>'inventory','tab'=>'items','new_item'=>1]+$createContext)) ?>"><?= e($choiceText('Einzelstücke getrennt erfassen')) ?></a>
   <div id="inventory-individual-example">
    <p><strong><?= e($choiceText('Wenn es auf das einzelne Gerät ankommt.')) ?></strong> <?= e($choiceText('Jedes erhält einen eigenen Eintrag und eine eigene Inventarnummer.')) ?></p>
    <p><?= e($choiceText('Beispiel: Zwei Beamer werden einzeln geführt. Bei der Ausleihe wählst du genau den Beamer, den du herausgibst.')) ?></p>
   </div>
  </div>
  <div class="iv-create-option <?= $quantityMode?'is-selected':'' ?>">
   <a class="btn button <?= !$quantityMode?'btn-outline theme-secondary':'btn-solid theme-primary' ?>" data-inventory-mode <?= $quantityMode?'aria-current="page"':'' ?> aria-describedby="inventory-quantity-example" href="<?= e(url(['view'=>'inventory','tab'=>'items','new_item'=>1,'create_mode'=>'quantity']+$createContext)) ?>"><?= e($choiceText('Gleiche Dinge als Menge erfassen')) ?></a>
   <div id="inventory-quantity-example">
    <p><strong><?= e($choiceText('Wenn gleiche Dinge gemeinsam gezählt werden.')) ?></strong> <?= e($choiceText('Ein Eintrag enthält die gesamte Anzahl.')) ?></p>
    <p><?= e($choiceText('Beispiel: Vier Bierzeltbänke. Wenn du zwei ausgibst, bleiben zwei im Lager.')) ?></p>
   </div>
  </div>
 </div>
 <p class="muted"><?= e($choiceText('Auch bei einem gemeinsamen Eintrag sind unterschiedliche Stückpreise und Seriennummern möglich. Bereits angelegte Gegenstände werden durch diese Auswahl nicht umgestellt.')) ?></p>
</section>
