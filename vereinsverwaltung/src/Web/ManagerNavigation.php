<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once dirname(__DIR__, 1).'/Finance/Accounting/FinanceNavigation.php';
require_once __DIR__.'/PeopleNavigation.php';
require_once dirname(__DIR__, 1).'/Communication/EditorialModules.php';

/** Navigation only: every destination still passes WorkspaceAccess and service checks. */
final class ManagerNavigation
{
 public static function website():array
 {
  return [
   ['id'=>'contents','label'=>'Seiten & Programm','routes'=>[['Übersicht',['view'=>'website']],['Seiten',['view'=>'website','tab'=>'pages']],['Menü',['view'=>'website','tab'=>'navigation']],['Aktuelles',['view'=>'website','tab'=>'news']],['Fotogalerie',['view'=>'website','tab'=>'gallery']],['Veranstaltungen',['view'=>'website','tab'=>'events']],['Tickets verwalten',['view'=>'website','tab'=>'events','tickets'=>'1']]]],
   ['id'=>'design','label'=>'Design & Vorlagen','routes'=>[['Designvorschläge',['view'=>'website','tab'=>'design']]]],
   ['id'=>'automation','label'=>'Automatisierung','routes'=>[['Automatische Vorschläge',['view'=>'website','tab'=>'automation']]]],
   ['id'=>'module-settings','label'=>'Moduleinstellungen','routes'=>[['Einstellungen',['view'=>'website','tab'=>'module-settings']]]],
   ['id'=>'technical','label'=>'Verbindungen','routes'=>[['Verbindungen & Veröffentlichung',['view'=>'website','tab'=>'technical']]]],
  ];
 }
 public static function settings():array
 {
  return [
   ['id'=>'club','label'=>'Verein & Zugänge','routes'=>[['Vereinsdaten',['view'=>'settings']],['Steuern & Vorsteuer',['view'=>'settings','section'=>'tax']],['Rollen & Rechte',['view'=>'settings','tab'=>'roles']],['Postfächer & Versand',['view'=>'settings','section'=>'mail']],['Zusatzfelder',['view'=>'settings','section'=>'fields']],['Briefanreden',['view'=>'settings','section'=>'salutations']],['Mitgliederausweise & Wallet',['view'=>'settings','tab'=>'member-cards']]]],
   ['id'=>'rates','label'=>'Beiträge & Nummern','routes'=>[['Beitragssätze',['view'=>'settings','tab'=>'rates']],['Nummernkreis',['view'=>'settings','tab'=>'numbers']],['Leistungen',['view'=>'settings','tab'=>'services']],['Bankverbindung',['view'=>'settings','section'=>'bank']],['SEPA für Mitglieder',['view'=>'settings','tab'=>'sepa']]]],
   ['id'=>'templates','label'=>'Vorlagen & Briefpapier','routes'=>[['Textbausteine',['view'=>'settings','tab'=>'texts']],['Briefpapier',['view'=>'settings','tab'=>'paper']]]],
   ['id'=>'status','label'=>'Systemstatus','routes'=>[['Status & Verfügbarkeit',['view'=>'settings','tab'=>'status']],['Cache',['view'=>'settings','tab'=>'cache']],['Erweiterungen',['view'=>'settings','tab'=>'extensions']],['API-Zugänge',['view'=>'settings','tab'=>'api']],['Systemverwaltung',['view'=>'settings','tab'=>'system']]]],
   ['id'=>'languages','label'=>'Sprachen','routes'=>[['Website & Sprache',['view'=>'settings','tab'=>'languages']]]],
  ];
 }
 public static function key(array $route):string
 {
  if(($route['view']??'')==='documents'&&($route['tab']??'')==='workflows')return 'organisation:workflows';
  if(($route['view']??'')==='documents'&&($route['tab']??'')==='inspections')return 'organisation:inspections';
  if(($route['view']??'')==='portal'&&($route['tab']??'')==='signatures')return 'portal:signatures';
  $route=EditorialModules::route($route);$view=$route['view']??'dashboard';$tab=$route['tab']??'';
  if($view==='settings')return $tab==='account'?'personal:account':'settings:'.(in_array($tab,['status','cache','extensions','api','system'],true)?'status':($tab==='languages'?'languages':(in_array($tab,['rates','numbers','services','sepa'],true)||($route['section']??'')==='bank'?'rates':(in_array($tab,['texts','paper'],true)?'templates':'club'))));
  if($id=FinanceNavigation::active($route))return 'finance:'.$id;
  if($view==='documents'&&$tab==='activity-reports')return 'organisation:activity-reports';
  if($people=PeopleNavigation::groupFor($route))return 'people:'.$people;
  if(in_array($view,['social','newsletter'],true))return 'communication:editorial';
  if($view==='press')return 'press:contents';
  if($view==='website')return 'website:'.(in_array($tab,['design','automation','module-settings','press','technical'],true)?$tab:'contents');
  return match($view){'help'=>'help','inventory','material'=>'organisation:inventory','documents'=>'organisation:documents','gema'=>'organisation:gema','free'=>'organisation:lists','calendar'=>'portal:calendar','minutes'=>'portal:minutes','chat'=>'portal:chat','messages'=>'portal:messages','portal'=>$tab==='vouchers'?'portal:vouchers':($tab==='safety'?'portal:safety':'portal:own'),default=>'home'};
 }
 public static function groups(WorkspaceAccess $access,?\Kulturhus\Native\Language\Catalog $languageCatalog=null):array
 {
  $item=static fn($id,$label,$routes)=>['id'=>$id,'label'=>$label,'routes'=>$routes];
  $groups=[
   ['id'=>'start','label'=>'Start','icon'=>'home','items'=>[$item('home','Übersicht',[['view'=>'dashboard']])]],
   ['id'=>'people','label'=>'Mitglieder & Kontakte','icon'=>'users','items'=>[
    $item('people:members','Mitglieder',[['view'=>'members','list'=>'current']]),
    $item('people:contacts','Kontakte',[['view'=>'members','list'=>'contacts']]),
    $item('people:applications','Mitgliedsanträge',[['view'=>'portal','tab'=>'application']]),
    $item('people:occasions','Geburtstage & Jubiläen',[['view'=>'members','list'=>'birthdays'],['view'=>'members-extra','tab'=>'anniversaries']]),
   ]],
   ['id'=>'finance','label'=>'Finanzen','icon'=>'bank','items'=>array_map(static fn($g)=>$item('finance:'.$g['id'],$g['label'],array_column($g['children'],'route')),FinanceNavigation::groups())],
   ['id'=>'organisation','label'=>'Organisation','icon'=>'file','items'=>[
    $item('organisation:documents','Dokumente & Nachweise',[['view'=>'documents'],['view'=>'documents','tab'=>'checks']]),
    $item('organisation:workflows','Gefährdungsbeurteilung',[['view'=>'documents','tab'=>'workflows']]),
    $item('organisation:inspections','Gefährdungsprüfung',[['view'=>'documents','tab'=>'inspections']]),
    $item('organisation:activity-reports','Tätigkeitsberichte',[['view'=>'documents','tab'=>'activity-reports','report_kind'=>'assembly'],['view'=>'documents','tab'=>'activity-reports','report_kind'=>'tax']]),
    $item('organisation:inventory','Inventar & Ausleihe',[['view'=>'inventory','tab'=>'where'],['view'=>'inventory','tab'=>'loans']]),
    $item('organisation:gema','GEMA',[['view'=>'gema']]),$item('organisation:lists','Freie Listen',[['view'=>'free']]),
   ]],
   ['id'=>'portal','label'=>'Vereinsportal','icon'=>'mail','items'=>[
    $item('portal:vouchers','Tickets & Gutscheine',[['view'=>'portal','tab'=>'vouchers'],['view'=>'pos']]),
    $item('portal:signatures','Meine Unterschriften',[['view'=>'portal','tab'=>'signatures']]),
    $item('portal:calendar','Kalender',[['view'=>'calendar']]),$item('portal:minutes','Protokolle',[['view'=>'minutes']]),
    $item('portal:safety','Sicher helfen',[['view'=>'portal','tab'=>'safety']]),$item('portal:own','Mein Profil & Belege',[['view'=>'portal','tab'=>'own'],['view'=>'portal','tab'=>'documents'],['view'=>'portal','tab'=>'bank']]),
   ]],
   ['id'=>'website','label'=>'Website','icon'=>'globe','items'=>array_map(static fn($g)=>$item('website:'.$g['id'],$g['label'],array_column($g['routes'],1)),self::website())],
   ['id'=>'communication','label'=>'Kommunikation','icon'=>'mail','items'=>[
    $item('portal:chat','Chat',[['view'=>'chat']]),
    $item('portal:messages','E-Mails & Briefe',[['view'=>'messages']]),
    $item('communication:editorial','Social & Newsletter',[['view'=>'social','section'=>'planning'],['view'=>'newsletter','section'=>'planning']]),
    $item('press:contents','Presse',[['view'=>'press']]),
   ]],
   ['id'=>'settings','label'=>'Einstellungen','icon'=>'settings','items'=>array_map(static fn($g)=>$item('settings:'.$g['id'],$g['label'],array_column($g['routes'],1)),self::settings())],
  ];
  foreach($groups as &$group){foreach($group['items'] as &$row){$allowed=array_values(array_filter($row['routes'],static fn($r)=>self::destination($access,$r)));$row['route']=$allowed[0]??null;}unset($row);$group['items']=array_values(array_filter($group['items'],static fn($r)=>$r['route']!==null));}unset($group);
  $groups=array_values(array_filter($groups,static fn($g)=>count($g['items'])>0));return $languageCatalog?\Kulturhus\Native\Language\ManagerLabels::groups($groups,$languageCatalog->messages()):$groups;
 }
 public static function search(WorkspaceAccess $access,?\Kulturhus\Native\Language\Catalog $languageCatalog=null):array
 {
  $entries=[];foreach(require __DIR__.'/Navigation.php' as $g)foreach($g[2] as $row){if(($row[1]['view']??'')==='website'&&($row[1]['tab']??'')==='technical')continue;$entries[]=[$row[0],EditorialModules::route($row[1])];}
  foreach(array_merge(self::website(),EditorialModules::navigation('social'),EditorialModules::navigation('newsletter'),EditorialModules::navigation('press'),self::settings()) as $g)foreach($g['routes'] as $row)$entries[]=$row;
  foreach(FinanceNavigation::groups() as $g)foreach($g['children'] as $row)$entries[]=[$row['label'],$row['route']];
  $entries[]=['Mein Account · Passwort & Zwei-Faktor-Anmeldung',['view'=>'settings','tab'=>'account']];
  foreach(\Kulturhus\Verein\Communication\Mailbox::ACCOUNTS as$mailbox=>$label)$entries[]=['Postfach '.$label.' · Nachrichten, Antworten und Zuständigkeiten',['view'=>'messages','tab'=>'inbox','mailbox_account'=>$mailbox]];
  $entries[]=['Meine Angaben ändern · Änderungsantrag',['view'=>'portal','tab'=>'changes']];
  $entries[]=['Gefährdungsbeurteilung · Entwurf, Prüfung & Unterschriften',['view'=>'documents','tab'=>'workflows']];
  $entries[]=['Meine Unterschriften',['view'=>'portal','tab'=>'signatures']];
  $entries[]=['Gutscheine · Essen, Getränke & Guthaben',['view'=>'portal','tab'=>'vouchers']];
  foreach(ActivityReportReview::KINDS as $kind=>$label)$entries[]=[$label,['view'=>'documents','tab'=>'activity-reports','report_kind'=>$kind]];
  $entries[]=['Mitglieder · Datenänderungen prüfen',['view'=>'members','tab'=>'changes']];
  $entries[]=['Hilfe · Bank, Rechnungen und Mitglieder',['view'=>'help']];
  $out=[];foreach($entries as [$label,$route]){if(in_array($route['view']??'', ['social','newsletter'],true)&&!isset($route['section'])&&(!isset($route['tab'])||in_array($route['tab'],['social','newsletter'],true))){if(self::destination($access,['view'=>'social','section'=>'planning']))$route=['view'=>'social','section'=>'planning'];elseif(self::destination($access,['view'=>'newsletter','section'=>'planning']))$route=['view'=>'newsletter','section'=>'planning'];$label='Social & Newsletter';}if(!self::destination($access,$route))continue;$keyRoute=$route;ksort($keyRoute);$key=http_build_query($keyRoute);$out[$key]=['label'=>$label,'route'=>$route];}$out=array_values($out);return $languageCatalog?\Kulturhus\Native\Language\ManagerLabels::search($out,$languageCatalog->messages()):$out;
 }
 public static function sections(array $definitions,WorkspaceAccess $access):array
 {
  $out=[];foreach($definitions as $g){$g['routes']=array_values(array_filter($g['routes'],static fn($row)=>self::destination($access,$row[1])));if($g['routes'])$out[]=$g;}return $out;
 }
 private static function destination(WorkspaceAccess $access,array $route):bool
 {
  if(!$access->allows($route))return false;
  if(($route['view']??'')==='website'&&($route['tab']??'')===''){
   foreach(['pages','news','gallery','events'] as $tab)if($access->allows(['view'=>'website','tab'=>$tab]))return true;
   return false;
  }
  return true;
 }
}
