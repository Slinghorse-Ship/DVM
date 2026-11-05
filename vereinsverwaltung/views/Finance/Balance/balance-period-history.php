<?php declare(strict_types=1);
$periodHistory=array_values(array_reverse(array_filter($service->history($periodId),static fn(array$e):bool=>in_array($e['payload']['kind'],['correction_prepared','correction_confirmed','correction_discarded','carry_refreshed','reaffirmed'],true))));
if(!$periodHistory)return;
$periodHistoryPages=(int)ceil(count($periodHistory)/20);$periodHistoryRaw=input($_GET,'period_history_page','1');
$periodHistoryValid=preg_match('/\A[1-9][0-9]{0,3}\z/D',$periodHistoryRaw)&&(int)$periodHistoryRaw<=$periodHistoryPages;
$periodHistoryPage=$periodHistoryValid?(int)$periodHistoryRaw:1;
$periodHistoryUrl=static fn(int$page):string=>url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_history_page'=>(string)$page]);
$periodHistoryDate=static fn(string$t):string=>(new DateTimeImmutable($t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
?>
<details class="card" data-balance-period-history <?= array_key_exists('period_history_page',$_GET)?'open':'' ?>><summary><?= e($periodText('Entscheidungen zu diesem Zeitraum')) ?> (<?= count($periodHistory) ?>)</summary>
<?php if(!$periodHistoryValid): ?><p role="alert"><?= e($periodText('Verlaufsseite dieses Abschlusszeitraums vollständig auswählen.')) ?></p><a class="btn btn-outline" href="<?= e($periodHistoryUrl(1)) ?>"><?= e($periodText('Verlauf neu öffnen')) ?></a>
<?php else: ?>
<?php foreach(array_slice($periodHistory,($periodHistoryPage-1)*20,20)as$event):$kind=$event['payload']['kind'];$note=in_array($kind,['correction_prepared','carry_refreshed'],true)?$event['payload']['data']['proof']['input']['reason']:$event['payload']['data']['evidence'];$label=match($kind){'correction_prepared'=>'Vorbereitet','correction_confirmed'=>'Bestätigt','correction_discarded'=>'Verworfen','carry_refreshed'=>'Anfangsbestände übernommen','reaffirmed'=>'Erneut bestätigt'}; ?>
<article><h3><?= e($periodHistoryDate($event['payload']['at']).' · '.$periodText($label)) ?></h3><p><?= e($note) ?></p><a class="btn btn-outline" data-balance-period-history-link="<?= (int)$event['revision'] ?>" href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_original'=>(string)$event['revision'],'period_original_sha256'=>$event['sha256']])) ?>"><?= e($periodText('Diesen ursprünglichen Ausgang lesen')) ?></a></article>
<?php endforeach ?>
<?php if($periodHistoryPages>1): ?><nav aria-label="<?= e($periodText('Verlauf dieses Abschlusszeitraums')) ?>"><p><?= e($periodText('Seite')) ?> <?= $periodHistoryPage ?> / <?= $periodHistoryPages ?></p><?php if($periodHistoryPage>1): ?><a class="btn btn-outline" href="<?= e($periodHistoryUrl($periodHistoryPage-1)) ?>"><?= e($periodText('Neuere Entscheidungen')) ?></a><?php endif ?><?php if($periodHistoryPage<$periodHistoryPages): ?><a class="btn btn-outline" href="<?= e($periodHistoryUrl($periodHistoryPage+1)) ?>"><?= e($periodText('Frühere Entscheidungen')) ?></a><?php endif ?></nav><?php endif ?>
<?php endif ?></details>
