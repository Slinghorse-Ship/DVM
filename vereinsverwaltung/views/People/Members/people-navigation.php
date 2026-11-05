<?php
require_once __DIR__.'/../../../src/Web/PeopleNavigation.php';
$peopleNavigationMessages=$managerLanguageMessages??(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberCatalogueMessages::messages('de'));
$peopleNavigationText=static fn(string $source):string=>\Kulturhus\Native\Language\MemberCatalogueMessages::text($source,$peopleNavigationMessages);
$peopleNavRoute=$peopleNavRoute??array_replace($_GET,['view'=>$view,'tab'=>$tab]);
$peopleNavGroup=\Kulturhus\Verein\PeopleNavigation::groupFor($peopleNavRoute);
$peopleNavActive=\Kulturhus\Verein\PeopleNavigation::activeIndex($peopleNavRoute);
?>
<nav class="module-tabs people-nav<?= !empty($peopleNavCatalogue)?' member-catalogue-nav':'' ?>" aria-label="<?= e($peopleNavigationText($peopleNavGroup==='contacts'?'Kontaktlisten':($peopleNavGroup==='occasions'?'Geburtstage und Jubiläen':'Mitgliederlisten'))) ?>">
<?php foreach(\Kulturhus\Verein\PeopleNavigation::tabs($peopleNavGroup) as $peopleNavIndex=>$peopleNavItem):if(!$workspaceAccess->allows($peopleNavItem['route']))continue; ?>
<a href="<?= e(url($peopleNavItem['route'])) ?>" <?= $peopleNavIndex===$peopleNavActive?'aria-current="page"':'' ?>><?= e($peopleNavigationText($peopleNavItem['label'])) ?></a>
<?php endforeach ?></nav>
<?php unset($peopleNavRoute,$peopleNavCatalogue); ?>
