<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

$websiteContact=(new \Kulturhus\Verein\WebsiteContactStatus($db,(string)$_SESSION['actor']))->forContact((string)$profile['id']);
$newsletterLabels=['confirmed'=>$memberProfileText('Bestätigt'),'pending'=>$memberProfileText('Bestätigung ausstehend'),'unsubscribed'=>$memberProfileText('Abgemeldet')];
?>
<section class="card"><h2><?= e($memberProfileText('Newsletter')) ?> & <?= e($memberProfileText('Presse')) ?></h2>
<?php if($websiteContact['state']==='unavailable'): ?><p><?= e($memberProfileText('Der Newsletter-Status benötigt eine persönliche Anmeldung im nativen CMS.')) ?></p>
<?php elseif($websiteContact['state']==='no-address'): ?><p><?= e($memberProfileText('Nach dem Speichern einer E-Mail-Adresse wird der vorhandene Website-Status geprüft.')) ?></p>
<?php else: ?>
<?php if(!$websiteContact['matches']): ?><p><?= e($memberProfileText('Für diese E-Mail-Adresse liegt keine Newsletter-Anmeldung vor.')) ?></p><?php endif ?>
<?php foreach($websiteContact['matches'] as $match): ?><p><strong><?= e($newsletterLabels[$match['status']]??$match['status']) ?></strong><?= !empty($match['confirmed_at'])?' · '.e((new DateTimeImmutable($match['confirmed_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y. H:i')):'' ?></p><?php endforeach ?>
<?php if($websiteContact['state']==='ambiguous'||($websiteContact['shared_address_profiles']??0)>1): ?><p><?= e($memberProfileText('Diese Adresse ist mehrfach zugeordnet. Der Newsletter-Status gilt für die E-Mail-Adresse; eine eindeutige Zuordnung zur Person ist damit nicht belegt.')) ?></p><?php endif ?>
<p><?= e($memberProfileText('Presseverteiler:')) ?> <?= e($websiteContact['press']?(implode(', ',array_map(static fn(array $row):string=>($row['outlet']!==''?$row['outlet']:$memberProfileText('Pressekontakt')).($row['active']?'':' ('.$memberProfileText('inaktiv').')'),$websiteContact['press']))):$memberProfileText('Kein Eintrag')) ?></p><p class="muted"><?= e($memberProfileText('Aktueller Stand aus dem eigenen CMS. Neue Bestätigungen und Abmeldungen erscheinen beim nächsten Laden.')) ?></p>
<?php endif ?><p class="muted"><?= e($memberProfileText('Die optionale Zugangsmail meldet das Mitglied nicht zum Newsletter an. Mitgliedschaft, Newsletter und Presse bleiben getrennt.')) ?></p></section>
