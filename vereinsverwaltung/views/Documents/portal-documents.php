<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Documents/PersonalDocumentState.php';
require_once __DIR__.'/../../src/Documents/PersonalDocumentText.php';
require_once __DIR__.'/../../src/Documents/WorkflowPager.php';
$pdState=$personalDocumentState??\Kulturhus\Verein\Documents\PersonalDocumentState::prepare(new \Kulturhus\Verein\PortalDocuments($db,(string)$_SESSION['actor']),$_GET,$websiteCalendar??null);
$pdMessages=$documentActionMessages??[];$pdI=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::text($source,$pdMessages);
$pdLocale=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->locale():'de';
$pdDate=static fn(string $date):string=>\Kulturhus\Verein\Documents\HseInterfaceText::date($date,$pdLocale,$pdMessages);
$pdMoney=static fn(int $cents):string=>\Kulturhus\Verein\Documents\PersonalDocumentText::money($cents,$pdLocale);
$pdQuery=['view'=>'portal','tab'=>'documents']+$pdState['query'];$pdTitles=['invoices'=>'Beitragsrechnungen','shared'=>'Für mich freigegebene Dokumente','vouchers'=>'Meine Gutscheine'];
?>
<link rel="stylesheet" href="document-workflows.css?v=2"><link rel="stylesheet" href="personal-documents.css?v=1">
<section class="content dwf-workspace personal-document-workspace" data-personal-documents>
<header class="dwf-heading"><div><h1><?= e($pdI('Meine Belege')) ?></h1><p><?= e($pdI('Eigene Rechnungen, persönliche Freigaben und Gutscheine')) ?></p></div></header>
<?php if((new \Kulturhus\Verein\VolunteerForm($db,(string)$_SESSION['actor']))->canUse()):?><p><a class="btn btn-outline theme-secondary dwf-button" href="?view=portal&amp;tab=safety&amp;volunteer=1&amp;volunteer_kind=general"><?= e($pdI('Mitgliedschaft & Mitarbeit bestätigen')) ?></a></p><?php endif ?>
<nav class="dwf-tabs" aria-label="<?= e($pdI('Persönliche Belegbereiche')) ?>"><?php foreach($pdTitles as$pdSection=>$pdTitle):?><a href="#personal-<?= e($pdSection) ?>"><?= e($pdI($pdTitle)) ?></a><?php endforeach ?></nav>
<?php if($pdState['profile_error']!==''):?><p class="dwf-alert" role="status"><?= e($pdI($pdState['profile_error'])) ?></p><?php endif ?>
<?php foreach($pdTitles as$pdSection=>$pdTitle):$pdPage=$pdState['pages'][$pdSection];[$pdSearch,$pdPageName]=\Kulturhus\Verein\Documents\PersonalDocumentState::FIELDS[$pdSection];?>
<section class="dwf-card personal-document-section" id="personal-<?= e($pdSection) ?>" data-personal-section="<?= e($pdSection) ?>"><h2><?= e($pdI($pdTitle)) ?></h2>
<?php if($pdState['profile_error']===''):?>
<form method="get" class="personal-document-search" data-live-search-page="<?= e($pdPageName) ?>"><?php foreach($pdQuery as$pdField=>$pdValue)if(!in_array($pdField,[$pdSearch,$pdPageName],true))echo hidden($pdField,$pdValue);echo hidden($pdPageName,'1');?><label><?= e($pdI('In diesen persönlichen Belegen suchen')) ?><input class="form-control" type="search" name="<?= e($pdSearch) ?>" value="<?= e($pdState['filters'][$pdSection]['query']) ?>"></label><button class="btn btn-solid theme-primary dwf-button"><?= e($pdI('Suchen')) ?></button><a class="btn btn-outline theme-secondary dwf-button" href="?<?= e(http_build_query(array_replace($pdQuery,[$pdSearch=>'',$pdPageName=>1]),'','&',PHP_QUERY_RFC3986)) ?>"><?= e($pdI('Suche zurücksetzen')) ?></a></form>
<?php if(isset($pdState['errors'][$pdSection])):?><p class="dwf-alert dwf-error" role="alert"><?= e($pdI($pdState['errors'][$pdSection]['message'])) ?></p><?php endif ?>
<?php echo \Kulturhus\Verein\Documents\WorkflowPager::render($pdPage,$pdPageName,$pdQuery,$pdI); ?>
<?php if($pdPage!==null&&!$pdPage['rows']):?><p><?= e($pdI('Keine passenden persönlichen Belege.')) ?></p><?php endif ?>
<?php if($pdPage!==null)foreach($pdPage['rows']as$pdRow):?><article class="personal-document-item" data-personal-original="<?= e($pdSection) ?>"><h3><?= e($pdRow['title']) ?></h3>
<?php if($pdSection==='invoices'):$pdPayment=['open'=>'Offen','partial'=>'Teilweise bezahlt','paid'=>'Bezahlt','cancelled'=>'Storniert','reversed'=>'Rückgängig gemacht'][$pdRow['payment_state']]??$pdRow['payment_state'];?><p><strong><?= e($pdI($pdPayment)) ?></strong> · <?= e((string)$pdRow['year']) ?> · <?= e((string)$pdRow['number']) ?> · <?= e($pdMoney((int)$pdRow['amount_cents'])) ?></p><p><a class="btn btn-outline theme-secondary dwf-button" href="?portal_document=<?= e(rawurlencode($pdRow['id'])) ?>"><?= e($pdI('Rechnungs-PDF herunterladen')) ?></a></p><?php if($pdRow['payment_state']==='paid'):?><p><?= e($pdI('Der Zahlungsstand folgt den zugeordneten Zahlungen. Die ausgestellte Originalrechnung bleibt unverändert.')) ?></p><?php endif ?>
<?php elseif($pdSection==='shared'):?><p><?= e($pdDate($pdRow['document_date'])) ?> · <?= e(\Kulturhus\Verein\Documents\HseInterfaceText::format('Freigegebene Dateifassung {revision}',['revision'=>$pdRow['revision']],$pdMessages)) ?></p><a class="btn btn-outline theme-secondary dwf-button" href="?portal_document=<?= e(rawurlencode($pdRow['id'])) ?>"><?= e($pdI('Freigegebenes Original herunterladen')) ?></a>
<?php else:?><p><?= e($pdDate((new DateTimeImmutable($pdRow['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d'))) ?></p><?php if($pdRow['benefit_rows']):?><ul><?php foreach($pdRow['benefit_rows']as$pdBenefit):?><li><?= e($pdBenefit['label']) ?> · <?= e($pdBenefit['unit']==='eur'?$pdMoney($pdBenefit['remaining']):($pdBenefit['remaining']===1?$pdI('1 Stück'):\Kulturhus\Verein\Documents\HseInterfaceText::format('{count} Stück',['count'=>$pdBenefit['remaining']],$pdMessages))) ?></li><?php endforeach ?></ul><?php else:?><p><?= e($pdI('Keine offenen Gutscheine.')) ?></p><?php endif ?>
<?php if($pdRow['notice']!==''):?><p role="status"><?= e($pdI($pdRow['notice'])) ?></p><?php endif ?><div class="dwf-actions"><?php if($pdRow['can_download']):?><a class="btn btn-solid theme-primary dwf-button" href="?view=portal&amp;tab=documents&amp;personal_voucher_file=<?= e(rawurlencode($pdRow['id'])) ?>"><?= e($pdI('Eigene Gutschein-PDF herunterladen')) ?></a><?php endif ?><a class="btn btn-outline theme-secondary dwf-button" href="?view=portal&amp;tab=vouchers&amp;voucher_scope=own&amp;voucher_event=<?= e(rawurlencode($pdRow['id'])) ?>"><?= e($pdI('Gutscheine ansehen')) ?></a></div>
<?php endif ?></article><?php endforeach ?>
<?php echo \Kulturhus\Verein\Documents\WorkflowPager::render($pdPage,$pdPageName,$pdQuery,$pdI); ?>
<?php endif ?></section><?php endforeach ?>
<?php if(isset($workflowManager)&&$workflowManager&&$workflowManager->allows(true))require __DIR__.'/portal-workflow-files.php'; ?>
</section>
