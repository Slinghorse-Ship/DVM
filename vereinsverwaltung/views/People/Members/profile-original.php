<?php declare(strict_types=1);
if(($_POST['action']??null)!=='save'||($error??'')===''||!$canWrite)return;
$profileOriginalMessages=$managerLanguageMessages??(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberProfileMessages::messages('de'));
$profileOriginalText=static fn(string $source):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$profileOriginalMessages);
$profileOriginalRoute=['view'=>'members'];
if($profile){$profileOriginalRoute+=scalarFields($_GET,['list','q','status','kind','sort','direction','as_of','page']);$profileOriginalRoute['id']=$profile['id'];}
elseif(($_GET['new']??null)==='1'&&!isset($_GET['id']))$profileOriginalRoute+=['new'=>'1','create_as'=>input($_GET,'create_as','member')];
if(isset($profileOriginalRoute['new'])){$originalCreationKey=$_GET['create_request']??($_POST['create_request']??null);if(is_string($originalCreationKey)&&preg_match('/\A[a-f0-9]{32}\z/D',$originalCreationKey))$profileOriginalRoute['create_request']=$originalCreationKey;}
$profileRecoveryLocale=$managerLanguageLocale??'de';
$profileRecoveryText=static fn(string $source):string=>\Kulturhus\Verein\Web\FormRecovery::text($source,$profileRecoveryLocale);
$profileRecoveryOwn=\Kulturhus\Verein\Web\MemberProfileForm::original($_POST);
$profileRecoveryDefinition=\Kulturhus\Verein\MemberCustomFields::definition($db,\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor'])['club_id']);
$profileRecoveryBlocked=\Kulturhus\Verein\Web\MemberProfileForm::blocked($_POST,$_GET,$profile['id']??'',$profileRecoveryDefinition);
$profileRecoveryConflict=$profile&&!$profileRecoveryBlocked&&is_string($_POST['version']??null)&&(string)$profile['version']!==$_POST['version'];
$profileRecoveryLabels=array_map($profileOriginalText,['kind'=>'Profilart','name'=>'Name','email'=>'E-Mail','phone'=>'Telefon','address'=>'Anschrift','birthday'=>'Geburtstag','legacy_member_number'=>'Alte Mitgliedsnummer','honorary'=>'Ehrenmitglied','notes'=>'Notizen']);
$profileRecoveryDisplay=$profileRecoveryOwn;$profileRecoveryStored=$profile;
foreach(['own','stored']as$which){$values=&${$which==='own'?'profileRecoveryDisplay':'profileRecoveryStored'};if(!is_array($values))continue;$kind=$values['kind']??null;if(is_string($kind)&&in_array($kind,['person','organisation'],true))$values['kind']=$profileOriginalText($kind==='person'?'Person':'Firma');$flag=$values['honorary']??'0';if(in_array($flag,[true,false,0,1,'0','1'],true))$values['honorary']=$profileOriginalText($flag?'Ja':'Nein');unset($values);}
$profileRecoverySavedCustom=\Kulturhus\Verein\MemberCustomFields::values($profile['custom_fields']??null);
foreach($profileRecoveryDefinition['rows']as$row){$field='custom:'.$row['id'];$profileRecoveryLabels[$field]=$row['name'];$own=is_array($profileRecoveryOwn['custom_fields']??null)?($profileRecoveryOwn['custom_fields'][$row['id']]??''):null;$saved=$profileRecoverySavedCustom[$row['id']]['value']??'';if($row['type']==='checkbox'){if(is_string($own)&&in_array($own,['','0','1'],true))$own=$profileOriginalText($own==='1'?'Ja':'Nein');$saved=$profileOriginalText($saved==='1'?'Ja':'Nein');}$profileRecoveryDisplay[$field]=$own;if(is_array($profileRecoveryStored))$profileRecoveryStored[$field]=$saved;}

?>
<section class="card member-profile-original" data-member-profile-original role="status">
<h2><?=e($profileRecoveryText('Deine Eingaben'))?></h2>
<p><?=e($profileRecoveryText($profileRecoveryBlocked?'Diese Eingabe konnte nicht übernommen werden. Öffne den Vorgang erneut und vergleiche die Angaben.':'Bitte die markierten Angaben prüfen und erneut speichern.'))?></p>
<?php if($profileRecoveryBlocked):echo \Kulturhus\Verein\Web\FormRecovery::fields($profileRecoveryDisplay,$profileRecoveryLabels,null,$profileRecoveryLocale);elseif($profileRecoveryConflict): ?>
<details open class="form-recovery-compare"><summary><?=e($profileRecoveryText('Gespeicherte Angaben vergleichen'))?></summary>
<?=\Kulturhus\Verein\Web\FormRecovery::fields($profileRecoveryDisplay,$profileRecoveryLabels,$profileRecoveryStored,$profileRecoveryLocale)?>
<p><?=e($profileRecoveryText('Prüfe die Unterschiede. Erst beim Speichern werden deine Angaben übernommen.'))?></p>
<button type="button" hidden class="btn btn-outline theme-secondary" data-form-recovery-current data-recovery-form="profile-form" data-recovery-target-field="id" data-recovery-target="<?=e($profile['id'])?>" data-recovery-versions="<?=e(json_encode(['version'=>(string)$profile['version'],'custom_fields_version'=>(string)($profileRecoveryDefinition['version']??$_POST['custom_fields_version']??'')],JSON_THROW_ON_ERROR))?>" data-recovery-message="<?=e($profileRecoveryText('Aktueller Stand zum Weiterarbeiten übernommen. Bitte Angaben prüfen und speichern.'))?>"><?=e($profileRecoveryText('Mit meinen Angaben weiterarbeiten'))?></button><p data-form-recovery-status aria-live="polite"></p>
</details>
<?php endif ?>
<a class="btn btn-outline theme-secondary button secondary" data-navigation-native href="<?=e(url($profileOriginalRoute))?>"><?=e($profileRecoveryText('Aktuellen Stand öffnen'))?></a>
<?=\Kulturhus\Verein\Web\FormRecovery::technical($profileRecoveryOwn,$profileRecoveryLocale)?>
</section>
