<?php declare(strict_types=1);
$creationMessages=$managerLanguageMessages??(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberProfileMessages::messages('de'));
$creationText=static fn(string $source):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$creationMessages);
$creationKind=input($_POST,'create_as',input($_GET,'create_as','member'));if(!in_array($creationKind,['contact','member'],true))$creationKind='member';
?>
<section class="card member-profile-original" data-profile-creation-status role="status">
<h2><?=e($creationText($profileCreationSaved?'Dieses Profil wurde bereits angelegt.':'Anlagevorgang neu prüfen'))?></h2>
<p><?=e($creationText($profileCreationSaved?'Die ursprüngliche Anlage wird nicht nochmals ausgeführt. Abweichende Eingaben bleiben als Original sichtbar. Für eine weitere Person einen neuen Anlagevorgang beginnen.':'Die ursprüngliche Anlagekennung wird nicht ersetzt. Den aktuellen Speicherstand prüfen oder bewusst einen neuen Anlagevorgang beginnen.'))?></p>
<?php if($profileCreationSaved): ?><a class="btn btn-outline theme-secondary button secondary" href="<?=e(url(['view'=>'members','id'=>$profileCreationSaved]))?>"><?=e($creationText('Bereits angelegtes Profil öffnen'))?></a><?php endif ?>
<?php if($canWrite): ?><a class="btn btn-outline theme-secondary button secondary" href="<?=e(url(['view'=>'members','new'=>'1','create_as'=>$creationKind]))?>"><?=e($creationText('Neuen Anlagevorgang beginnen'))?></a><?php endif ?>
</section>
