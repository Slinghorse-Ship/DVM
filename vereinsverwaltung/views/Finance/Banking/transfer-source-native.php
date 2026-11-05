<?php declare(strict_types=1);if(!$transferShownSource)return; ?>
<section class="card" data-transfer-source><h3><?=e($tr('Geprüfter Inhalt der Bankdatei'))?></h3>
<?php if($credit=$transferShownSource['credit_refund']??null): ?>
<section data-transfer-credit-source><h4><?=e($tr('Beleggebundene Gutschrift-Rückzahlung'))?></h4>
<dl><dt><?=e($tr('Zugehörige ursprüngliche Rechnung'))?></dt><dd><?=e($credit['parent_number'])?></dd>
<dt><?=e($tr('Ausgestellte Gutschrift'))?></dt><dd><?=e($credit['number'])?> · <?=e(euro($credit['credit_cents']))?></dd>
<dt><?=e($tr('Ursprünglicher Rechnungsempfänger'))?></dt><dd><pre><?="\n".e($credit['buyer_name'])?></pre></dd>
<dt><?=e($tr('Tatsächlicher ursprünglicher Zahlungseingang'))?></dt><dd><?=e(euro($credit['paid_cents']))?></dd>
<dt><?=e($tr('Bereits ausgestellte Gutschriften'))?></dt><dd><?=e(euro($credit['credited_cents']))?></dd></dl>
<a class="btn btn-outline theme-secondary" data-transfer-credit-original href="<?=e(url(['view'=>'finance','tab'=>'outgoing','invoice'=>$credit['id']]))?>"><?=e($tr('Ausgestellte Gutschrift mit Original-PDF öffnen'))?></a>
<a class="btn btn-outline theme-secondary" data-transfer-parent-original href="<?=e(url(['view'=>'finance','tab'=>'outgoing','invoice'=>$credit['parent_id']]))?>"><?=e($tr('Zugehörige ursprüngliche Rechnung öffnen'))?></a>
<p><?=e($tr('Empfänger und Höchstbetrag stammen aus der ausgestellten Gutschrift und ihrer ursprünglichen Rechnung. Die gespeicherte Bankverbindung wird vor Freigabe und Ausgabe erneut geprüft. Erst ein tatsächlich zugeordneter Kontoumsatz bezahlt die Gutschrift.'))?></p></section>
<?php endif ?>
<?php if($transferShownSource['recipient_kind']==='member-reimbursement'):?><section data-transfer-reimbursement-source><p><?=e($tr('Erstattungsberechtigte Person'))?>: <?=e($transferShownSource['recipient_contact_name'])?></p><p><?=e($tr('Ein freigegebener Erstattungsanspruch bleibt bei einem Mitgliedsaustritt bestehen. Für neue Freigaben und Bankdateien wird die aktuelle gespeicherte Bankverbindung derselben Person geprüft.'))?></p></section><?php endif ?>
<dl><dt><?=e($tr('Rechnungsnummer'))?></dt><dd><?=e($transferShownSource['number'])?></dd><dt><?=e($tr('Ausführungstag'))?></dt><dd><?=e($transferShownSource['execution_date'])?></dd><dt><?=e($tr('Überweisungsart'))?></dt><dd><?=e($tr($transferShownSource['transfer_mode']==='instant'?'Echtzeitüberweisung':'Normale SEPA-Überweisung'))?></dd><dt><?=e($tr('Überweisungsbetrag'))?></dt><dd><?=e(euro($transferShownSource['total_cents']))?></dd>
<?php foreach(['source'=>'Auftraggeber','recipient'=>'Empfänger']as$bankPart=>$label):?><dt><?=e($tr($label))?></dt><dd><pre><?="\n".e($transferShownSource[$bankPart]['name'])?></pre></dd><dt><?=e($tr('Name in der Bankdatei'))?></dt><dd><pre><?="\n".e($transferShownSource[$bankPart]['xml_name'])?></pre></dd><dt><?=e($tr('Bankkonto, geschützt dargestellt'))?></dt><dd><?=e($transferShownSource[$bankPart]['iban_masked'])?></dd><?php endforeach ?>
<dt><?=e($tr('Verwendungszweck in der Bankdatei'))?></dt><dd><pre><?="\n".e($transferShownSource['purpose'])?></pre></dd></dl>
<p><?=e($tr('Diese Bankdatei verwendet den angezeigten offenen Rechnungsbetrag. Eine Echtzeitüberweisung wird nicht automatisch als normale Überweisung ausgeführt. Die Bankfreigabe und eine gegebenenfalls erforderliche Empfängerprüfung erfolgen im getrennten Bankdialog.'))?></p></section>
