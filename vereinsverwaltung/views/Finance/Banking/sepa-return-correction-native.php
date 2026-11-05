<?php declare(strict_types=1);
if(!in_array($raw['operation'],['return_attach','return_existing','return_undo'],true)&&!array_key_exists('return_financials',$raw['data']))return;
?>
<section class="card" data-sepa-return-correction>
<h3><?=e($snt('Dokumentierte Rücklastschrift gemeinsam prüfen'))?></h3>
<p><?=e($snt('Den zurückgegebenen Einzug auswählen. Rückumsatz und Betrag ergeben sich aus dem ursprünglichen Vermerk; die Felder für einen neuen Rückumsatz und ein neues Dokument bleiben leer. Den tatsächlichen Banknachweis und den Grund vollständig angeben.'))?></p>
<p><?=e($snt('Bei einer noch nicht finanziell zugeordneten Rücklastschrift die ursprünglichen Zahlungsanteile auswählen. Bereits vorhandene Finanznachweise ausdrücklich verknüpfen. Eine gemeinsame Rücknahme macht diese Zuordnung rückgängig und lässt alle Originale erhalten. Spätere Zahlungen oder offene Bankaufträge zuerst klären.'))?></p>
<?php if($raw['operation']!=='return_existing'&&array_key_exists('return_financials',$raw['data'])):?><p><?=e($snt('Für diese Aktion die frühere Auswahl vorhandener Finanznachweise ausdrücklich leeren.'))?></p><?php foreach($raw['data']['return_financials']as$id):if(!$readonly)echo hidden('sepa_return_financials[]',$id);?><pre><?=e($id)?></pre><?php endforeach?><button class="btn btn-outline theme-secondary" name="sepa_intent" value="clear-financials"<?=$readonly?' disabled':''?>><?=e($snt('Auswahl vorhandener Finanznachweise leeren'))?></button><?php endif?>
<?php if($raw['operation']==='return_existing'):
$financialRows=$sepaState['return_financials'];$chosen=$raw['data']['return_financials']??[];$pageText=$snraw($_POST,'sepa_return_financial_page','1');$validPage=preg_match('/\A[1-9][0-9]{0,6}\z/D',$pageText)===1;$pages=max(1,(int)ceil(count($financialRows)/20));$page=$validPage?(int)$pageText:0;$shown=$page>=1&&$page<=$pages?array_slice($financialRows,($page-1)*20,20):[];$shownIds=array_column($shown,'id');
?>
<h4><?=e($snt('Vorhandene finanzielle Rückzahlungsnachweise'))?></h4>
<?php foreach($chosen as$id)if(!in_array($id,$shownIds,true)):?><?php if(!$readonly)echo hidden('sepa_return_financials[]',$id);?><pre><?=e($id)?></pre><?php endif ?>
<?php foreach($shown as$financial):?><label class="general-settings-check" data-sepa-return-financial-choice="<?=e($financial['id'])?>"><input class="check" type="checkbox" name="sepa_return_financials[]" value="<?=e($financial['id'])?>"<?=in_array($financial['id'],$chosen,true)?' checked':''?><?=$readonly?' disabled':''?>><span><?=e($financial['created_at'].' · '.euro($financial['cents']))?><pre><?=e($financial['reason'])?></pre></span></label><?php endforeach ?>
<?php if(!$shown):?><p role="status"><?=e($snt('Keine vorhandenen Nachweise auf dieser Seite. Einzug und Seitenzahl prüfen.'))?></p><?php endif ?>
<label><?=e($snt('Seite der vorhandenen Finanznachweise'))?><?php $snfield('sepa_return_financial_page',$pageText,$readonly);?></label><p><?=e(strtr($snt('Seite {page} von {pages}'),['{page}'=>$pageText,'{pages}'=>(string)$pages]))?></p>
<button class="btn btn-outline theme-secondary" name="sepa_intent" value="selection"<?=$readonly?' disabled':''?>><?=e($snt('Vorhandene Finanznachweise anzeigen'))?></button>
<?php if($chosen):?><button class="btn btn-outline theme-secondary" name="sepa_intent" value="clear-financials"<?=$readonly?' disabled':''?>><?=e($snt('Auswahl vorhandener Finanznachweise leeren'))?></button><?php endif ?>
<?php endif ?>
</section>
