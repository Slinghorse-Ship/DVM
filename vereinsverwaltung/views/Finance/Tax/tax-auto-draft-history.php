<?php declare(strict_types=1);
try{
$page=$_GET['tax_draft_page']??'1';if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Jahr und Bestätigungsseite vollständig prüfen.',422);
$draftHistory=(new \Kulturhus\Verein\TaxAutoRequests($db,(string)$_SESSION['actor']))->history($autoYear,(int)$page);
}catch(DomainException$e){echo '<p role="alert">'.e($taxText($e->getMessage())).'</p>';return;}
if(!$draftHistory['rows'])return;?>
<section class="card" data-tax-draft-history><h2><?=e($taxText('Gespeicherte Steuerbestätigungen'))?></h2><p><?=e($taxText('{count} Bestätigungen · Seite {page} von {pages}',$draftHistory))?></p><ul>
<?php foreach($draftHistory['rows']as$row):$stamp=(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s T');?><li><a href="<?=e(url($draftRoute+['tax_draft_outcome'=>$row['request_key']]))?>"><?=e($taxText('Jahr {year} · gespeicherte Fassung {version}',['year'=>$row['tax_year'],'version'=>$row['case_version']]))?> · <?=e($stamp)?></a></li><?php endforeach?></ul><nav aria-label="<?=e($taxText('Gespeicherte Steuerbestätigungen'))?>">
<?php foreach([-1=>'Vorherige',1=>'Nächste']as$offset=>$label):$page=$draftHistory['page']+$offset;if($page>=1&&$page<=$draftHistory['pages']):?><a class="btn btn-outline" href="<?=e(url($draftRoute+['tax_draft_page'=>(string)$page]))?>"><?=e($taxText($label))?></a><?php endif;endforeach?></nav></section>
