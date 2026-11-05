<?php declare(strict_types=1);
use Kulturhus\Verein\SettingsSchema;
if($tab!=='account'){$sectionNavDefinitions=\Kulturhus\Verein\ManagerNavigation::settings();$sectionNavPrefix='settings';$sectionNavLabel=\Kulturhus\Native\Language\ContentMessages::text('Einstellungsbereiche',$managerLanguageMessages??[]);require __DIR__.'/../Shell/section-navigation.php';}
if($tab==='roles'&&($roleNativeRoute??false)){require __DIR__.'/roles-native.php';return;}
if($tab==='paper'&&($papersettingsNativeRoute??false)){require __DIR__.'/paper-native.php';return;}
if($tab==='texts'&&($textsettingsNativeRoute??false)){require __DIR__.'/texts-native.php';return;}
if($tab===''&&($mailsettingsNativeRoute??false)){require __DIR__.'/mail-native.php';return;}
if($tab===''&&($taxsettingsNativeRoute??false)){require __DIR__.'/finance-tax-native.php';return;}
if($tab===''&&($invoicebankNativeRoute??false)){require __DIR__.'/finance-bank-native.php';return;}
if($tab===''&&($preferenceNativeRoute??false)){require __DIR__.'/finance-preferences-native.php';return;}
if($tab===''&&($generalNativeRoute??false)){require __DIR__.'/general-native.php';return;}
if($tab==='numbers'&&($numberingNativeRoute??false)){require __DIR__.'/numbering-native.php';return;}
if(in_array($tab,['rates','services'],true)&&($automationNativeRoute??false)){require __DIR__.'/automation-native.php';return;}
if(in_array($tab,['rates','services'],true)&&($billingNativeRoute??false)){require __DIR__.'/billing-native.php';return;}
if(in_array($tab,['rates','services'],true)&&($personalPriceNativeRoute??false)){require __DIR__.'/personal-prices-native.php';return;}
if(in_array($tab,['rates','services'],true)&&($assignmentNativeRoute??false)){require __DIR__.'/assignments-native.php';return;}
if(in_array($tab,['rates','services'],true)&&($tariffNativeRoute??false)){require __DIR__.'/tariffs-native.php';return;}
function settingsField(string $key,array $field,array $data,string $prefix='data'):void{
 [$label,$type,$limit,$default]=$field;$value=$data[$key]??$default;$name=$prefix.'['.$key.']';$id='setting-'.preg_replace('/[^a-z0-9_-]/i','-',$name);$required=$field[4]??false;
 if(!is_scalar($value))$value=$default;
 if($type==='asset')return;
 if($type==='checkbox'){ ?><label class="setting-check"><input class="check" type="checkbox" name="<?= e($name) ?>" value="1" <?= $value?'checked':'' ?>><?= e($label) ?></label><?php return;}
 ?><label for="<?= e($id) ?>" class="setting-field <?= $type==='textarea'?'wide':'' ?>"><?= e($label) ?><?= $required?' *':'' ?>
 <?php if($type==='select'): ?><select class="form-control" id="<?= e($id) ?>" name="<?= e($name) ?>"><?php foreach($limit as $v=>$text): ?><option value="<?= e($v) ?>" <?= (string)$value===(string)$v?'selected':'' ?>><?= e($text) ?></option><?php endforeach ?></select>
 <?php elseif($type==='textarea'): ?><textarea class="form-control" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="4" maxlength="<?= $limit ?>" <?= $required?'required':'' ?>><?= e(is_scalar($value)?$value:'') ?></textarea>
 <?php else: ?><input class="form-control" id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e(in_array($type,['email','url','color','date'],true)?$type:($type==='integer'?'number':'text')) ?>" value="<?= e(is_scalar($value)?$value:'') ?>" <?= $required?'required':'' ?> <?= $type==='integer'?'min="'.(int)$limit[0].'" max="'.(int)$limit[1].'" step="1"':'maxlength="'.(int)$limit.'"' ?>><?php endif ?></label><?php
}
function richField(string $name,string $label,string $value,int $max=6000):void{ ?>
<div class="rich-field"><label><?= e($label) ?><textarea class="form-control" name="<?= e($name) ?>" rows="<?= str_contains($name,'message')?'9':'4' ?>" maxlength="<?= $max ?>" data-rich-input><?= e($value) ?></textarea></label><div class="rich-toolbar" role="group" aria-label="<?= e($label) ?> formatieren"><button type="button" class="btn btn-outline theme-secondary secondary" data-format="**" aria-label="Fett"><strong>B</strong></button><button type="button" class="btn btn-outline theme-secondary secondary" data-format="*" aria-label="Kursiv"><em>I</em></button><button type="button" class="btn btn-outline theme-secondary secondary" data-format="__" aria-label="Unterstrichen"><u>U</u></button><select class="form-control" data-variable aria-label="Variable auswählen"><option value="">Variable wählen</option><?php foreach(SettingsSchema::VARIABLES as $key=>$text): ?><option value="<?= $key ?>"><?= e($text) ?></option><?php endforeach ?></select><button type="button" class="btn btn-outline theme-secondary secondary" data-insert-variable>Einfügen</button></div><div class="rich-preview" data-rich-preview aria-label="Formatierte Vorschau"><?= SettingsSchema::html($value) ?></div></div>
<?php }
$titles=[''=>'Vereinsdaten','account'=>'Mein Account','roles'=>'Rollen & Rechte','numbers'=>'Nummernkreis','rates'=>'Beiträge & Spenden','services'=>'Leistungen','texts'=>'Textbausteine','paper'=>'Briefpapier'];
if($tab==='')$titles['']=SettingsSchema::SECTIONS[input($_GET,'section','club')]??'Vereinsdaten';
$titles['account']=($managerLanguageMessages??[])[\Kulturhus\Native\Language\AccountMessages::key('Mein Account')]??$titles['account'];
?>
<div class="page-heading settings-heading"><div><h1><?= e($tab===''&&input($_GET,'section')==='finance_preferences'?'Finanz-Vorauswahl & Kontenanzeige':($tab===''&&input($_GET,'section')==='bank'?'Bankverbindung':$titles[$tab])) ?></h1></div></div>

<link rel="stylesheet" href="simple-settings.css?v=1"><script defer src="simple-settings.js?v=1"></script>
<div class="content settings-content simple-settings" data-simple-settings>
<?php if(in_array($tab,['rates','services'],true)){require __DIR__.'/settings-catalog.php';}
elseif($tab==='roles'){require __DIR__.'/settings-roles.php';}
else{
 $section=match($tab){''=>input($_GET,'section','club'),'texts'=>'texts_'.input($_GET,'group','contributions'),'numbers'=>'numbers',default=>$tab};
 if($tab===''&&!in_array($section,['club','salutations','bank','tax','finance_preferences','mail','structure','fields'],true))throw new DomainException('Unbekannter Bereich.');
 if($tab==='texts'&&!in_array($section,['texts_contributions','texts_invoices','texts_donations'],true))throw new DomainException('Unbekannte Textgruppe.');
 $saved=$settings->section($section);$sd=$saved['data'];$sv=$saved['version'];
 if(input($_POST,'action')==='settings-save'&&input($_POST,'section')===$section&&is_array($_POST['data']??null)){
  try{$sd=($error??'')!==''?SettingsSchema::formValues($section,$_POST['data'],$sd):SettingsSchema::normalize($section,$_POST['data']);}catch(DomainException){$sd=SettingsSchema::formValues($section,$_POST['data'],$sd);}
  $sv=input($_POST,'version');
 }

 if($section==='finance_preferences')require __DIR__.'/settings-finance-preferences.php';elseif($tab==='paper')require __DIR__.'/settings-paper.php';elseif($tab==='texts')require __DIR__.'/settings-texts.php';elseif($tab==='numbers')require __DIR__.'/settings-numbers.php';else require __DIR__.'/settings-profile.php';
 if($tab===''&&$section==='tax'){
  (static function()use($db):void{
   $certificateSettings=true;$base=['view'=>'finance','tab'=>'tax','tax_filings'=>'1'];$fid='';$year=(int)date('Y')-1;
   require __DIR__.'/../Finance/Tax/tax-certificate.php';
  })();
 }
 if($tab===''&&in_array($section,['bank','tax'],true)){echo '<details class="card"'.(input($_POST,'action')==='settings-registration'?' open':'').'><summary>Schnittstellen &amp; Verbindungen</summary>';require __DIR__.'/settings-registrations.php';echo '</details>';}
 $settingHistory=$settings->history($section);if($settingHistory): ?><details class="card settings-history"><summary>Änderungsverlauf</summary><div class="table-scroll"><table class="table"><thead><tr><th>Gespeichert</th><th>Bearbeiter</th></tr></thead><tbody><?php foreach($settingHistory as $revision): ?><tr><td><?= e($revision['happened_at']) ?></td><td><?= e($revision['actor_id']) ?></td></tr><?php endforeach ?></tbody></table></div></details><?php endif;
} ?>
</div>
