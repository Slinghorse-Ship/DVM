<?php declare(strict_types=1);
// Read existing current XML/source contracts; display never approves or sends XML.
if(empty($balanceReport['calculation']))return;
require_once __DIR__.'/../../../src/Finance/Balance/BalanceXmlOverview.php';
$mappingOverviewText=$annualSourceText??static fn(string$s):string=>$s;$mappingOverviewDisplay=new \Kulturhus\Verein\BalanceDisplayText($managerLanguageMessages??[]);
$mappingOverviewFormat=static fn(string$s,array$v):string=>strtr($mappingOverviewText($s),$v);
try{$xmlOverview=(new \Kulturhus\Verein\BalanceXmlOverview($db,(string)$_SESSION['actor']))->read($balanceReport);$mappingReview=$xmlOverview['mapping'];}
catch(DomainException $ex){echo '<p role="alert">'.e($mappingOverviewText($ex->getMessage())).'</p>';return;}
?>
<section class="card" aria-label="<?= e($mappingOverviewText('XML zum Jahresabschluss')) ?>"><h3><?= e($mappingOverviewText('XML zum Jahresabschluss').' '.$balanceReport['year']) ?></h3>
<?php if(!$xmlOverview['closed']): ?><p><?= e($mappingOverviewText('Das Jahr ist noch offen. Zuordnungen können vorbereitet werden; die endgültige XML-Prüfung setzt den festgeschriebenen Abschluss voraus.')) ?></p><?php endif ?>
<?php if(!$xmlOverview['filings']): ?><p><?= e($mappingOverviewText('Noch kein XML-Entwurf für dieses Jahr vorhanden.')) ?></p><?php endif ?>
<?php foreach($xmlOverview['filings']as$xmlFiling): ?><p><a href="<?= e(url(['view'=>'finance','tab'=>'tax','tax_year'=>$balanceReport['year'],'tax_filings'=>'1','filing'=>$xmlFiling['id']])) ?>"><?= e($mappingOverviewText('Gespeicherten XML-Entwurf öffnen')) ?></a><br><?= e($mappingOverviewText($xmlFiling['message'])) ?></p><?php endforeach ?>
<?php if($xmlOverview['ambiguous']): ?><p><?= e($mappingOverviewText('Mehrere Entwürfe gehören zu diesem Abschluss. Die jeweilige gespeicherte Zuordnung im gewählten Entwurf prüfen.')) ?></p><?php endif ?>
<p><?= e($mappingOverviewFormat('{saved} gespeicherte Zuordnungen übernommen · {unused} Konten ohne Bestände und Buchungen · {open} aktive Konten offen.',['{saved}'=>(string)$mappingReview['saved_count'],'{unused}'=>(string)$mappingReview['unused_count'],'{open}'=>(string)$mappingReview['review_count']])) ?></p>
<p><a class="btn btn-outline text-wrap" data-annual-mapping-overview-current href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$balanceReport['year'],'annual_mapping_review'=>'current'])) ?>"><?= e($mappingOverviewText('Aktuellen Zuordnungsstand öffnen')) ?></a></p></section>
<details class="card"><summary><?= e($mappingOverviewText('Kontenzuordnung prüfen').($mappingReview['review_count']?' · '.$mappingOverviewFormat('{count} offen',['{count}'=>(string)$mappingReview['review_count']]):'')) ?></summary>
<div class="table-scroll" tabindex="0" role="region" aria-label="<?= e($mappingOverviewText('Kontenzuordnung')) ?>"><table class="table"><thead><tr><th><?= e($mappingOverviewText('Konto')) ?></th><th><?= e($mappingOverviewText('Zuordnung im Jahresabschluss')) ?></th><th><?= e($mappingOverviewText('Status')) ?></th></tr></thead><tbody>
<?php foreach($mappingReview['rows']as$mappingOverviewAccountId=>$row): ?><tr><td><?= e(($row['skr_code']!==''?$row['skr_code'].' · ':'').$mappingOverviewDisplay->account($balanceReport['calculation']['accounts'][$mappingOverviewAccountId])) ?></td><td><?= e($row['status']==='unused'?$mappingOverviewText('Keine Anfangsbestände oder Buchungen'):($row['label']?:$mappingOverviewText($row['message']))) ?></td><td><?= e($mappingOverviewText(match($row['status']){'assigned'=>'Automatisch zugeordnet','saved'=>'Geprüfte Zuordnung gespeichert','unused'=>'Unbenutzt',default=>'Buchhaltung prüfen'})) ?></td></tr><?php endforeach ?>
</tbody></table></div></details>
<?php if($balanceReport['state']==='open'&&(new \Kulturhus\Verein\Finance($db,(string)$_SESSION['actor']))->canWrite()): require __DIR__.'/balance-mapping-review.php';endif ?>
<?php $catalogCoverage=\Kulturhus\Verein\BalanceTaxMapping::coverage($balanceReport['year']);$catalogMapped=$catalogCoverage['bookable']-$catalogCoverage['missing']; ?>
<details class="card"><summary><?= e($mappingOverviewFormat('SKR42-Kontenplan {year} · {mapped} von {bookable} XML-Regeln',['{year}'=>(string)$balanceReport['year'],'{mapped}'=>(string)$catalogMapped,'{bookable}'=>(string)$catalogCoverage['bookable']])) ?></summary>
<p><?= e($mappingOverviewFormat('{percent} % Kontenabdeckung · {missing} Originalkonten noch ohne geprüfte XML-Regel.',['{percent}'=>number_format(100*$catalogMapped/$catalogCoverage['bookable'],2,',','.'),'{missing}'=>(string)$catalogCoverage['missing']])) ?></p>
<p><?= e($mappingOverviewFormat('{automatic} automatische und {conditional} bedingte Regeln. Bedingte Regeln benötigen die angegebenen Belege, Anlagenpläne oder ausdrücklichen Entscheidungen. Die Zuordnung der tatsächlich bebuchten Konten und die amtliche XML-Prüfung werden gesondert geprüft.',['{automatic}'=>(string)$catalogCoverage['automatic'],'{conditional}'=>(string)$catalogCoverage['conditional']])) ?></p>
<?php if($catalogCoverage['missing_accounts']): ?><div class="table-scroll" tabindex="0" role="region" aria-label="<?= e($mappingOverviewText('Offene Originalkonten')) ?>"><table class="table"><thead><tr><th><?= e($mappingOverviewText('SKR42-Konto')) ?></th><th><?= e($mappingOverviewText('Originalbezeichnung')) ?></th></tr></thead><tbody>
<?php foreach($catalogCoverage['missing_accounts']as$row): ?><tr><td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></details>
