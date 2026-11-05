<?php declare(strict_types=1);
$annualReadUrl=static fn(string$key):string=>url($annualTaxRoute+['annual_tax_outcome'=>$key]);
$annualKnownKey=null;$annualToken=$annualTaxRaw['tax_preview_token']??null;if(is_string($annualToken)){$annualReceipt=$_SESSION['tax_previews'][$annualToken]??null;if(is_array($annualReceipt)&&is_string($annualReceipt['request_key']??null))$annualKnownKey=$annualReceipt['request_key'];}
if($annualTaxError!==''):?>
<section class="notice annual-tax-error" role="alert" data-annual-tax-error><strong><?=e($taxText($annualTaxError))?></strong><details><summary><?=e($taxText('Bekannte ursprüngliche Jahresangaben'))?></summary><pre><?=e(json_encode($annualTaxRaw,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details><?php if($annualKnownKey):?><a class="btn btn-outline" data-annual-tax-read-first target="_blank" rel="noopener" href="<?=e($annualReadUrl($annualKnownKey))?>"><?=e($taxText('Gespeicherten Jahresausgang in neuem Tab lesen'))?></a><?php endif?><a href="<?=e(url($annualTaxRoute))?>"><?=e($taxText('Jahresformular ausdrücklich neu öffnen'))?></a></section>
<?php endif;
if($annualTaxOutcome===null)return;?>
<section class="card" data-annual-tax-outcome><h2><?=e($taxText('Gespeicherten Jahresausgang lesen'))?></h2><p><?=e($taxText('Kennung'))?>: <?=e($annualTaxOutcome['key'])?></p>
<?php if($annualTaxOutcome['original']):$taxPreview=$annualTaxOutcome['original'];require __DIR__.'/tax-preview.php';?><a class="btn btn-outline" href="<?=e(url(['view'=>'finance','tab'=>'tax','tax_resume'=>$taxPreview['document']['id']]))?>"><?=e($taxText('Mit diesen Angaben einen neuen Stand vorbereiten'))?></a>
<?php else:?><p><?=e($taxText('Für diese Kennung ist noch kein gespeicherter Jahresausgang lesbar. Zuerst denselben Auftrag erneut lesen; keinen zweiten Stand beginnen.'))?></p><?php endif?>
<a class="btn btn-outline" href="<?=e($annualReadUrl($annualTaxOutcome['key']))?>"><?=e($taxText('Gespeicherten Jahresausgang erneut lesen'))?></a></section>
