<?php
if(!isset($gemaText))$gemaText=$managerText??static fn(string $source):string=>$source;
$gemaFormat=static fn(string $source,array $values):string=>\Kulturhus\Native\Language\CalendarMessages::format($source,$values,$gemaText);
if(input($_GET,'event')==='')echo '<link rel="stylesheet" href="gema-interface.css?v=1">';
if(input($_GET,'event')!==''){
    $event=$registry->appointment(input($_GET,'event'));
    ?><div class="page-heading"><div><h1><?= e($gemaText('GEMA ·')) ?> <?= e($event['title']) ?></h1><p><?= e(dateDe(substr(localDateTime($event['starts_at']),0,10))) ?> · <?= e(timeDe($event['starts_at'])) ?> · <?= e($event['location']) ?></p></div><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'gema','year'=>input($_GET,'year',substr(localDateTime($event['starts_at']),0,4))])) ?>"><?= e($gemaText('Zur GEMA-Übersicht')) ?></a></div><?php
    require __DIR__.'/event-gema.php';return;
}
$gemaService=new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry);
$gemaYear=(int)input($_GET,'year',date('Y'));
$gemaRows=$gemaService->yearEvents($gemaYear);
$approved=count(array_filter($gemaRows,fn($r)=>$r['status']==='exempt'));
$planned=count(array_filter($gemaRows,fn($r)=>$r['planned']==='1'&&!in_array($r['status'],['requested','exempt'],true)));
$requested=count(array_filter($gemaRows,fn($r)=>$r['status']==='requested'));
?>
<div class="page-heading"><h1><?= e($gemaText('GEMA-Übersicht')) ?></h1></div>
<div class="content gema-overview">
    <form method="get">
        <?= hidden('view','gema') ?>
        <label><?= e($gemaText('Kalenderjahr')) ?><input class="form-control" type="number" name="year" min="2000" max="2100" value="<?= $gemaYear ?>"></label>
        <button class="btn btn-solid theme-primary"><?= e($gemaText('Anzeigen')) ?></button>
    </form>
    <?php if($registry->calendarNotice()!==''): ?><p role="alert"><?= e($gemaText($registry->calendarNotice())) ?></p><?php endif ?>
    <p><?= e($gemaFormat('Kostenübernahmen: {approved} bestätigt · {requested} beantragt · {planned} geplant.',['approved'=>$approved,'requested'=>$requested,'planned'=>$planned])) ?></p>
    <div class="table-scroll" tabindex="0" aria-label="<?= e($gemaFormat('Veranstaltungen {year}',['year'=>$gemaYear])) ?>">
        <table class="table">
            <thead><tr><th><?= e($gemaText('Veranstaltung')) ?></th><th><?= e($gemaText('Anmeldung')) ?></th><th><?= e($gemaText('Zahlung')) ?></th><th><?= e($gemaText('Musikliste (Setlist)')) ?></th></tr></thead>
            <tbody>
            <?php foreach($gemaRows as $r): ?>
                <tr>
                    <td><a href="<?= e(url(['view'=>'gema','event'=>$r['event_id'],'year'=>$gemaYear])) ?>"><?= e(dateDe($r['event_date']).' · '.$r['event_title']) ?></a></td>
                    <td><?= e($gemaText(\Kulturhus\Verein\EventGema::STATES[$r['status']])) ?></td>
                    <td><?= e($gemaText($r['payment_state'])) ?></td>
                    <td><?= e($gemaText(['check'=>'Prüfen','not_required'=>'Nicht erforderlich','pending'=>'Offen','submitted'=>'Eingereicht'][$r['setlist']])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if(!$gemaRows): ?><p><?= e($gemaText('Für dieses Jahr sind keine Veranstaltungen vorhanden.')) ?></p><?php endif ?>
</div>
