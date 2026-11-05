<?php declare(strict_types=1);
if($r['kind']!=='contribution'||!\Kulturhus\Verein\DocumentCatalog::query($db,'SELECT 1 FROM member_dues_people WHERE club_id=? AND entry_id=?',[$r['club_id'],$r['id']])->fetchColumn())return;
$invoice=\Kulturhus\Verein\DocumentCatalog::query($db,'SELECT * FROM contribution_invoices WHERE club_id=? AND entry_id=?',[$r['club_id'],$r['id']])->fetch();
$invoiceOriginalError='';if($invoice)try{$invoice=(new \Kulturhus\Verein\ContributionInvoices($db,(string)$_SESSION['actor']))->currentOriginal($r['id'])['invoice'];}catch(DomainException $failure){$invoiceOriginalError=$failure->getMessage();}
?>
<section class="card" data-contribution-invoice>
<div class="section-heading"><h2><?=e($financeFrameText('Jahresbeitragsrechnung'))?></h2>
<?php if($invoice&&$invoiceOriginalError===''): ?><a class="btn btn-outline theme-secondary button secondary" href="<?=e(url(['view'=>'documents','doc'=>$invoice['document_id']]))?>"><?=e($financeFrameText($invoice['state']==='draft'?'Rechnungsentwurf öffnen':'Rechnungs-PDF öffnen'))?></a><?php endif ?></div>
<?php if($invoiceOriginalError!==''): ?><p role="alert"><?=e($financeFrameText($invoiceOriginalError))?></p>
<?php elseif($invoice&&$invoice['state']==='draft'): ?><p><?=e($financeFrameText('Für die fertige Rechnung fehlen noch: {fields}.',['fields'=>implode(', ',array_map($financeFrameText,json_decode($invoice['missing_fields'],true,512,JSON_THROW_ON_ERROR)))]))?></p>
<?php elseif($invoice): ?><p><?=e($financeFrameText('Die ausgestellte Rechnung ist mit ihrem ursprünglichen Datenstand gespeichert.'))?></p>
<?php else: ?><p><?=e($financeFrameText('Für diesen Jahresbeitrag ist noch keine Rechnung abgelegt.'))?></p><?php endif ?>
<p class="muted"><?=e($financeFrameText('Zahlungszuordnung, Rechnung und Bestätigung sind getrennte Nachweise. Eine Rechnung bestätigt keinen Zahlungseingang.'))?></p>
<?php if($finance->canWrite()&&!\Kulturhus\Verein\Web\ContributionInvoiceForm::blocked($_POST,$_GET,$r['id'])&&$invoiceOriginalError===''&&(!$invoice||$invoice['state']==='draft')&&!in_array($r['payment_state'],['cancelled','reversed'],true)): ?><form method="post"><?=csrf().hidden('action','contribution-invoice').hidden('entry_id',$r['id'])?><button class="btn btn-solid theme-primary"><?=e($financeFrameText($invoice?'Angaben erneut prüfen & Rechnung erstellen':'Beitragsrechnung erstellen'))?></button></form><?php endif ?>
</section>
