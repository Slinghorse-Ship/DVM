<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Web/ManagerNavigation.php';
$workspaceAccess=$workspaceAccess??new \Kulturhus\Verein\WorkspaceAccess($db,(string)$_SESSION['actor']);
$sectionNavGroups=\Kulturhus\Verein\ManagerNavigation::sections($sectionNavDefinitions,$workspaceAccess);
$sectionNavTab=in_array($sectionNavPrefix,['website','social','newsletter'],true)?($websiteTab??input($_GET,'tab')):($tab??input($_GET,'tab'));
$sectionNavCurrent=\Kulturhus\Verein\ManagerNavigation::key(array_replace($_GET,['view'=>$sectionNavPrefix,'tab'=>$sectionNavTab]));
$sectionNavChildren=[];
$managerSectionNavigationRendered=true;
$sectionNavText=static fn(string $source):string=>\Kulturhus\Native\Language\ContentMessages::text($source,$managerLanguageMessages??[]);
?>
<nav class="module-tabs section-main-nav" aria-label="<?= e($sectionNavLabel) ?>">
<?php foreach($sectionNavGroups as $g):$selected=$sectionNavCurrent===$sectionNavPrefix.':'.$g['id'];if($selected)$sectionNavChildren=$g['routes']; ?><a href="<?= e(url($g['routes'][0][1])) ?>" <?= $selected?'aria-current="true"':'' ?>><?= e($sectionNavText($g['label'])) ?></a><?php endforeach ?></nav>
<?php if(count($sectionNavChildren)>1): ?><nav class="module-tabs section-sub-nav" aria-label="<?= e($sectionNavLabel) ?> – <?= e($sectionNavText('Unterseiten')) ?>">
<?php foreach($sectionNavChildren as [$label,$route]):$selected=($route['tab']??'')===$sectionNavTab&&($sectionNavPrefix!=='settings'||($sectionNavTab!==''||($route['section']??'club')===input($_GET,'section','club')))&&($sectionNavPrefix!=='website'||$sectionNavTab!=='events'||(($route['tickets']??'')==='1')===(input($_GET,'tickets')==='1')); ?><a href="<?= e(url($route)) ?>" <?= $selected?'aria-current="page"':'' ?>><?= e($sectionNavText($label)) ?></a><?php endforeach ?></nav><?php endif ?>
