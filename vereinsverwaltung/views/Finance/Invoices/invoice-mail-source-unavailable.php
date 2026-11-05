<?php declare(strict_types=1);
$mailSourceLabels=['mail_only'=>'Ursprüngliche Nachricht','mail_state'=>'Nachrichtenstand','mail_q'=>'Nachrichtensuche','mail_page'=>'Nachrichtenseite','version'=>'Ursprüngliche Version','archive'=>'Ablegen / Wiederherstellen','reason'=>'Grund'];
?>
<section class="card" data-invoice-mail-unavailable><h3><?=e($mailText('Die ursprüngliche Nachrichtenauswahl ist nicht verfügbar'))?></h3>
<p role="alert"><?=e($mailText($invoiceMailSourceState['message']))?></p><p><?=e($mailText('Es wurde keine andere Nachricht oder Nachrichtenseite geöffnet.'))?></p>
<?php if($invoiceMailSourceState['raw']):?><h4><?=e($mailText('Übermittelte Angaben'))?></h4><dl class="mail-source-originals">
<?php foreach($invoiceMailSourceState['raw']as$mailSourceOriginal):?><dt><?=e($mailText($mailSourceLabels[$mailSourceOriginal['field']]))?> · <?=e($mailText($mailSourceOriginal['from']==='link'?'Angabe im Link':'Angabe im Formular'))?></dt><dd><?php if(!is_string($mailSourceOriginal['value'])):?><span><?=e($mailText('Mehrteilige Angabe'))?></span><?php endif;?><pre><?=e(is_string($mailSourceOriginal['value'])?$mailSourceOriginal['value']:json_encode($mailSourceOriginal['value'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR))?></pre></dd><?php endforeach;?></dl><?php endif;?>
<div class="actions">
<?php if($invoiceMailSourceState['retry']):?><a class="btn btn-outline theme-secondary" data-invoice-mail-retry href="<?=e(url($invoiceMailSourceState['retry']+['mail_read'=>bin2hex(random_bytes(8))]))?>#emails-ohne-beleg"><?=e($mailText('Ursprüngliche Nachrichtenauswahl erneut prüfen'))?></a><?php endif;?>
<?php if($invoiceMailSourceState['submitted']):?><a class="btn btn-outline theme-secondary" data-invoice-mail-submitted href="<?=e(url($invoiceMailSourceState['submitted']))?>#emails-ohne-beleg"><?=e($mailText('Übermittelte Nachricht gesondert prüfen'))?></a><?php endif;?>
<a class="btn btn-outline theme-secondary" data-invoice-mail-list href="<?=e(url($invoiceMailSourceState['list_route']))?>#emails-ohne-beleg"><?=e($mailText($invoiceMailSourceState['list_keeps_query']?'Nachrichtenliste mit dieser Suche öffnen':'Noch zu lesen ohne diese Filter öffnen'))?></a>
</div></section>
