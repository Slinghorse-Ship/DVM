<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

if(!$profile||$profile['kind']!=='person'||!$memberships)return;
require_once __DIR__.'/../../../src/People/Members/MemberBankVault.php';
$profileBankAllowed=false;$profileBankSummary=null;$profileBankError='';
try{$profileBankVault=\Kulturhus\Verein\MemberBankVault::fromEnvironment($db,(string)$_SESSION['actor'],!empty($production));$profileBankAllowed=$profileBankVault->canAccess($profile['id']);if($profileBankAllowed)$profileBankSummary=$profileBankVault->summary($profile['id']);}catch(DomainException){$profileBankError=$memberProfileText('Der geschützte Bankdatenbereich ist derzeit nicht verfügbar. Bitte die Vereinsverwaltung informieren.');}
?>
<section class="card" id="mitglied-bankdaten"><h2><?= e($memberProfileText('Bankverbindung')) ?> &amp; <?= e($memberProfileText('SEPA')) ?></h2>
<?php if($profileBankError!==''): ?><p role="alert"><?= e($memberProfileText($profileBankError)) ?></p><?php elseif($profileBankAllowed): ?>
<?php if($profileBankSummary): ?><p><?= e($profileBankSummary['iban_masked']!==''?($memberProfileText('Bankverbindung hinterlegt: {iban}',['iban'=>$profileBankSummary['iban_masked']])):$memberProfileText('Noch keine aktuelle Bankverbindung hinterlegt.')) ?></p><p><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'portal','tab'=>'bank','person'=>$profile['id']])) ?>"><?= e($profileBankSummary['iban_masked']!==''?$memberProfileText('Bankdaten ansehen oder ändern'):$memberProfileText('Bankdaten hinterlegen')) ?></a></p><p><?= e($memberProfileText('Vollständige Angaben werden erst nach Ihrer Passwortbestätigung angezeigt. Ihre eingeschaltete Zwei-Faktor-Anmeldung bleibt dabei wirksam. Bankdaten können auch ohne aktives SEPA hinterlegt werden.')) ?></p>
<?php else: ?><p role="alert"><?= e($memberProfileText($profileBankError)) ?></p><?php endif ?>
<?php else: ?><p><?= e($memberProfileText('Bankdaten können das Mitglied selbst, der Superuser und der aktuell bestätigte Kassenwart mit Finanz-Schreibrecht ansehen und ändern. Eine sonstige Verwaltungs- oder Vorstandsberechtigung allein genügt nicht. Voraussetzung ist eine derzeit aktive oder passive Mitgliedschaft.')) ?></p><?php endif ?>
</section>
