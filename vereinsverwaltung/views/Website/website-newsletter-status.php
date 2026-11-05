<?php declare(strict_types=1);
$automation=$native['automation'];
$periodPaused=!empty($automation['period_paused']);
$automatic=$automation['campaign']??null;
$canPausePeriod=!$automatic||in_array($automatic['status'],['draft','queued','sending'],true);
?>
<section class="card" id="newsletter-automation"><h2>Automatischer Newsletter</h2>
<p><strong><?= e($automation['period_label']) ?></strong> · Versand <?= e($automation['send_label']) ?></p>
<p role="status"><?= $periodPaused?'Diese Ausgabe wird ausgesetzt.':(!$native['mail_allowed']?'Der automatische Versand ist auf diesem PC gesperrt.':(!$automation['enabled']?'Alle automatischen Ausgaben sind gestoppt.':'Automatischer Versand aktiv.')) ?></p>
<?php if($native['mail_allowed']&&$canPausePeriod): ?>
<?php if(!$periodPaused):
    $simple('newsletter/period-pause','Diese Ausgabe aussetzen',['period'=>$automation['period'],'paused'=>'1'],$automation['period_version']);
else: ?>
<p>Spätere Ausgaben bleiben unverändert.</p>
<details><summary>Diese Ausgabe wieder aktivieren</summary><p>Ist der Versand bereits fällig, kann er beim nächsten automatischen Lauf beginnen. Bereits versendete Mails werden nicht erneut versendet.</p>
<?php $simple('newsletter/period-pause','Ausgabe wieder aktivieren',['period'=>$automation['period'],'paused'=>'0'],$automation['period_version']); ?>
</details>
<?php endif;endif ?>
<?php if($automatic): ?><p><a href="<?= e($link(['item'=>$automatic['id']])) ?>"><?= e($automatic['subject']) ?></a></p>
<?php if($automatic['status']==='sending'): ?><p>Bereits versendete Mails können nicht zurückgeholt werden. Eine gerade laufende Zustellung kann noch abgeschlossen werden.</p><?php endif ?>
<?php else: ?><p>Entwurf ab <?= e($automation['prepare_label']) ?></p><?php endif ?>
<?php if($native['mail_allowed']): ?>
<details><summary><?= empty($automation['globally_paused'])?'Alle automatischen Ausgaben stoppen':'Alle automatischen Ausgaben wieder aktivieren' ?></summary>
<?php if(empty($automation['globally_paused'])): ?><p>Auch spätere Ausgaben werden bis zur erneuten Aktivierung nicht automatisch versendet.</p>
<?php $simple('newsletter/automation','Alle automatischen Ausgaben stoppen',['paused'=>'1']);
else: ?><p>Fällige Ausgaben können versendet werden. Einzeln ausgesetzte Ausgaben bleiben ausgesetzt.</p>
<?php $simple('newsletter/automation','Automatik wieder aktivieren',['paused'=>'0']);endif ?>
</details>
<?php endif ?>
<details><summary>Empfängerzahlen</summary><div class="native-actions">
<?php foreach($native['counts'] as $key=>$value):if(is_scalar($value)): ?><span><strong><?= e((string)$value) ?></strong> <?= e(['confirmed'=>'Bestätigt','pending'=>'Bestätigung ausstehend','unsubscribed'=>'Abgemeldet','all'=>'Alle','events'=>'Veranstaltungen','news'=>'Neuigkeiten'][$key]??$key) ?></span><?php endif;endforeach ?>
</div></details>
</section>
