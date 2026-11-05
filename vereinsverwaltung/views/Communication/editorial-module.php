<?php declare(strict_types=1);
$editorialModule=$view;$editorialTab=input($_GET,'tab','overview');if($editorialTab==='')$editorialTab='overview';
$moduleWorkspace=new \Kulturhus\Verein\EditorialModuleWorkspace($db,(string)$_SESSION['actor'],$view);
if(!$moduleWorkspace->canRead()||!in_array($editorialTab,\Kulturhus\Verein\EditorialModules::tabs($view),true))throw new DomainException('Dieser Modulbereich ist nicht verfügbar.');
$moduleLabel=\Kulturhus\Verein\EditorialModules::LABELS[$view];
?>
<div class="page-heading"><div><h1><?= e($moduleLabel) ?></h1><p><?= $view==='social'?'Beiträge, Storys und Veröffentlichungen':($view==='press'?'Pressekontakte, Entwürfe und Kampagnen':'Ausgaben, Gestaltung und Empfänger') ?></p></div></div>
<?php $sectionNavDefinitions=\Kulturhus\Verein\EditorialModules::navigation($view);$sectionNavPrefix=$view;$sectionNavLabel=$moduleLabel.'-Bereiche';$websiteTab=$editorialTab;require __DIR__.'/../Shell/section-navigation.php'; ?>
<div class="content editorial-workspace" data-editorial-module="<?= e($view) ?>">
<?php if(in_array($editorialTab,\Kulturhus\Verein\EditorialModules::EXTRA,true)):require __DIR__.'/editorial-tools.php';
else:
 $originalWebsite=$website;$website=$moduleWorkspace;$websiteTab=$editorialTab;ob_start();
 try{require __DIR__.'/../Website/website-native.php';}
 catch(DomainException $moduleError){ob_clean();echo '<p class="error" role="status">'.e($moduleError->getMessage()).'</p>';}
 finally{$website=$originalWebsite;}
 echo ob_get_clean();
endif ?>
</div>
