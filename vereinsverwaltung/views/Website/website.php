<?php declare(strict_types=1);
use Kulturhus\Verein\{WebsiteWorkspace};
$websiteAreas=array_intersect_key($website->areas(),array_fill_keys(\Kulturhus\Verein\EditorialModules::AREAS['website'],true));$moduleWorkspace=new \Kulturhus\Verein\EditorialModuleWorkspace($db,(string)$_SESSION['actor'],'website');if($moduleWorkspace->canRead())foreach(['design'=>['Design & Vorlagen','Gestaltungsvorschläge und Vorschau','file'],'automation'=>['Automatisierung','Vorschläge automatisch vorbereiten','calendar'],'module-settings'=>['Moduleinstellungen','Eigene Design- und Vorschlagseinstellungen','settings']] as $key=>$info)$websiteAreas[$key]=$info;$websiteTab=input($_GET,'tab');$pressOnly=array_keys($websiteAreas)===['press'];if($pressOnly&&$websiteTab==='')$websiteTab='press';
if($websiteTab!==''&&!isset($websiteAreas[$websiteTab]))throw new DomainException('Dieser Website-Bereich ist nicht verfügbar.');
$websiteConnection=$websiteTab==='technical'?$website->connectionStatus():null;

$websiteLabels=['draft'=>'Entwurf','review'=>'Zur Prüfung','archived'=>'Archiviert'];
$websiteEditing=isset($_GET['draft'])||isset($_GET['site_page'])||isset($_GET['new_article'])||input($_POST,'action')==='website-save';
?>
<div class="page-heading"><div><h1><?= e($websiteTab===''?'Website':$websiteAreas[$websiteTab][0]) ?></h1><p>Website & Redaktion · Kulturhus Jaderberg e. V.</p></div><a class="btn btn-outline theme-secondary button secondary" href="<?= e(WebsiteWorkspace::publicUrl()) ?>" target="_blank" rel="noopener noreferrer">Website ansehen <?= icon('external') ?><span class="sr-only"> (neuer Tab)</span></a></div>
<?php $sectionNavDefinitions=\Kulturhus\Verein\ManagerNavigation::website();$sectionNavPrefix='website';$sectionNavLabel='Website-Bereiche';require __DIR__.'/../Shell/section-navigation.php'; ?>
<div class="content website-workspace"><?php if(in_array($websiteTab,['pages','news'],true)): ?><p><a href="<?= e(url(['view'=>'website','tab'=>$websiteTab,'draft_state'=>'active'])) ?>">Textentwürfe</a> · <a href="<?= e(url(['view'=>'website','tab'=>$websiteTab,'draft_state'=>'archived'])) ?>">Archivierte Textentwürfe</a></p><?php endif ?>
<?php if(in_array($websiteTab,['design','automation','module-settings'],true)){$editorialModule='website';$editorialTab=$websiteTab;require __DIR__.'/../Communication/editorial-tools.php';echo '</div>';return;} ?>
<?php if($websiteTab==='technical'): ?>
<?php if($invitations->canSend()): ?><section class="card"><h2>Vergangene Facebook-Veranstaltungen</h2><a href="?view=website&amp;tab=technical&amp;history=1">Abruf und Übernahme öffnen</a></section><?php if(isset($_GET['history']))require __DIR__.'/../Events/facebook-history.php';endif ?>
<div class="integration-note"><div><?= icon('globe') ?></div><div><strong><?= $websiteConnection['connected']?'Website verbunden':'Website nicht verbunden' ?></strong><p><?= $websiteConnection['connected']?'Website-Inhalte, Bilder und Verteiler direkt in den Formularen des Vereinsmanagers bearbeiten.':e($websiteConnection['message']) ?></p></div></div>
<?php if(($websiteConnection['status']['data_origin']??'')==='live-copy'): ?><p class="muted">Website-Datenkopie von kulturhus.de · Stand <?= e($websiteConnection['status']['copied_at']??'') ?>. Änderungen bleiben lokal.</p><?php endif ?>
<section class="card website-intro"><div><span class="website-eyebrow">Eine Zentrale für euren Verein</span><h2>Kulturhus.de von hier aus verwalten</h2><p>Seiten, Aktuelles, Fotos und Programm werden hier verwaltet. Social Media und Newsletter haben eigene Module.</p><p class="muted"><?= $websiteConnection['connected']?'Die Redaktionsbereiche sind mit der Website dieser Umgebung verbunden.':'Die Website-Verbindung ist in dieser Umgebung nicht eingerichtet.' ?> Getrennte Textentwürfe behalten ihren Versionsverlauf.</p></div><a class="btn btn-solid theme-primary button" href="?view=website&amp;tab=pages">Seiten bearbeiten</a></section>
<?php endif ?>
<?php if($websiteEditing):require __DIR__.'/website-editor.php';
elseif(isset($_GET['draft_state'])&&in_array($websiteTab,['pages','news'],true)):require __DIR__.'/website-drafts.php';
elseif($websiteTab!==''):require __DIR__.'/website-native.php';
else:$localDrafts=isset($websiteAreas['pages'])?array_merge($website->drafts('page'),$website->drafts('news')):[]; ?>
<section class="card"><div class="website-draft-counts"><span><strong><?= count($localDrafts) ?></strong> Textentwürfe</span><span><strong><?= count(array_filter($localDrafts,fn($d)=>$d['status']==='review')) ?></strong> zur Prüfung</span></div></section>
<div class="website-grid"><?php foreach($websiteAreas as $area=>[$label,$description,$ico]): ?><section class="card website-area"><div class="website-area-title"><span class="website-area-icon"><?= icon($ico) ?></span><h2><?= e($label) ?></h2></div><p><?= e($description) ?></p><div class="website-area-links"><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'website','tab'=>$area])) ?>">Bereich öffnen</a></div></section><?php endforeach ?></div>
<?php endif ?></div>
