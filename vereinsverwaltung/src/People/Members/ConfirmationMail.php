<?php declare(strict_types=1);
namespace Kulturhus\Verein\People\Members;
use PDO;
use Kulturhus\Verein\{MembershipApplications,ContributionRates,ApplicationMail,DocumentPreview,ProductionRuntime};
use Kulturhus\Native\Shared\{Db,Identity};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Cms\{TemplatePolicy,FieldPolicy};

/** The applicant reviews the exact submitted answers and published conditions before confirming. */
final class ConfirmationMail
{
 public static function capture(PDO $db,string $club,array $input,array $config,array $rates,int $version,bool $consent,bool $newsletter):array
 {
  $native=ManagerBridge::clubKey($club);$identity=ProductionRuntime::nativeManager()?Identity::forClub($db,$native):['name'=>(string)Db::run($db,'SELECT name FROM clubs WHERE id=?',[$club])->fetchColumn(),'origin'=>(string)getenv('VEREIN_PUBLIC_URL')];
  $answers=[];foreach(MembershipApplications::FIELDS as$key=>$label)if(($config['fields'][$key]??'hidden')!=='hidden'&&is_string($input[$key]??null))$answers[$key]=trim($input[$key]);
  $type=$input['membership_type']??'single';$answers+=['membership_type'=>$type,'annual_fee_cents'=>$rates[$type==='single'?'single':'family']['amount_cents'],'family_members'=>trim($input['family_members']??''),'family_reference'=>$type==='family_member'?trim($input['family_reference']??''):''];
  $legal=[];
  if(ProductionRuntime::nativeManager()){
   [$where,$args]=TemplatePolicy::publicFilter($db,$native,'p');
   foreach(['Satzung'=>['verein/satzung','satzung'],'Datenschutzhinweise'=>['datenschutz']]as$title=>$slugs)foreach($slugs as$slug){
    $row=Db::run($db,'SELECT p.id,p.published_revision,r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.club_id=p.club_id AND r.page_id=p.id AND r.revision=p.published_revision WHERE ('.$where.') AND p.published_slug=? LIMIT 1',[...$args,$slug])->fetch();if(!$row)continue;
    $page=FieldPolicy::publicSource($db,$native,json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR));$html=$page['body']??'';foreach($page['content_sections']??[]as$section)$html.='<h2>'.htmlspecialchars($section['heading']??'',ENT_QUOTES,'UTF-8').'</h2>'.($section['body_html']??'');
    $text=trim(html_entity_decode(strip_tags(preg_replace('~</(?:p|h[1-6]|li|tr|section)>~i',"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8'));if(strlen($text)>150000)throw new \DomainException('Veröffentlichte Antragsunterlagen sind zu umfangreich.');
    $legal[]=['title'=>$title,'url'=>rtrim($identity['origin'],'/').'/'.$slug.'/','text'=>$text,'revision'=>(int)$row['published_revision'],'sha256'=>hash('sha256',$row['payload_json'])];break;
   }
  }
  return['schema'=>1,'club'=>$identity['name'],'origin'=>$identity['origin'],'created_at'=>gmdate(DATE_ATOM),'answers'=>$answers,'notice_title'=>$config['notice_title'],'notice_text'=>$config['notice_text'],'configuration_version'=>$version,'consent'=>$consent,'newsletter'=>$newsletter,'legal'=>$legal];
 }
 public static function message(array $review,string $intro,string $subject,string $origin):array
 {
  if(($review['schema']??null)!==1||!is_array($review['answers']??null)||($review['origin']??'')!==$origin)throw new \DomainException('Ursprüngliche Antragsunterlagen prüfen.');
  $a=$review['answers'];$rows=[];foreach(MembershipApplications::FIELDS as$key=>$label)if(isset($a[$key])&&$a[$key]!=='')$rows[]=[$label,$a[$key]];
  $rows[]=['Mitgliedschaft und Jahresbeitrag',ContributionRates::applicationLabel($a)];foreach(['family_members'=>'Weitere Familienangehörige','family_reference'=>'Bestehende Familie / Beitragszahler']as$key=>$label)if(($a[$key]??'')!=='')$rows[]=[$label,$a[$key]];
  $rows[]=['Zahlungsart','Rechnung nach bestätigter Aufnahme'];$rows[]=['Antragshinweise zur Kenntnis genommen',$review['consent']?'Ja':'Nein'];$rows[]=['Newsletter gewünscht',$review['newsletter']?'Ja · separate E-Mail-Bestätigung erforderlich':'Nein'];
  $e=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$html='<p>Bitte prüfe deinen vollständigen Antrag. Erst mit deiner Bestätigung wird er dem Vorstand zur Entscheidung vorgelegt.</p><h2>Deine Antragsangaben</h2><table role="presentation" style="width:100%;border-collapse:collapse">';$text=$intro."\n\nDeine Antragsangaben\n";
  foreach($rows as[$label,$value]){$html.='<tr><th style="text-align:left;vertical-align:top;padding:8px;border-bottom:1px solid #d7cdbb">'.$e($label).'</th><td style="padding:8px;border-bottom:1px solid #d7cdbb;white-space:pre-wrap">'.$e((string)$value).'</td></tr>';$text.=$label.': '.$value."\n";}
  $html.='</table><h2>'.$e($review['notice_title']).'</h2><p>'.nl2br($e($review['notice_text'])).'</p>';$text.="\n".$review['notice_title']."\n".$review['notice_text']."\n";
  $sections=[['title'=>'Deine Antragsangaben','columns'=>['Angabe','Inhalt'],'rows'=>$rows],['title'=>'Erklärung und Hinweise · Fassung '.$review['configuration_version'],'columns'=>['Angabe','Inhalt'],'rows'=>[[$review['notice_title'],$review['notice_text']]]]];
  foreach($review['legal']as$legal){$html.='<p><a href="'.$e($legal['url']).'">'.$e($legal['title']).'</a> · Die bei Antragstellung veröffentlichte Fassung liegt auch im beigefügten PDF.</p>';$text.="\n".$legal['title'].': '.$legal['url']."\n";$sections[]=['title'=>$legal['title'].' · veröffentlichte Fassung '.$legal['revision'],'columns'=>['Quelle','Inhalt'],'rows'=>[[$legal['url'],$legal['text']]]];}
  $warning='Antrag zur E-Mail-Bestätigung. Eine Mitgliedschaft entsteht erst durch Annahme des Vorstandes. Diese Kopie enthält deine eingegangenen Angaben und die bei Antragstellung veröffentlichten Unterlagen.';
  $pdf=DocumentPreview::pdf(['record'=>['public_copy'=>true,'compact'=>true,'title'=>'Dein Mitgliedsantrag','club'=>$review['club'],'reference'=>'Online-Antrag vom '.$review['created_at'],'created_at'=>$review['created_at'],'warning'=>$warning,'sections'=>$sections]]);
  preg_match('~https://[^\s]+\?confirm_application=[a-f0-9]{64}~',$intro,$match);if(!isset($match[0]))throw new \DomainException('Ursprünglicher Bestätigungslink fehlt.');
  return['subject'=>$subject,'text'=>$text,'html'=>ApplicationMail::html($intro,$origin,$subject,$match[0],'Mitgliedsantrag bestätigen','MITGLIED WERDEN',$review['club'],$html),'attachments'=>[['name'=>'dein-mitgliedsantrag-mit-unterlagen.pdf','mime'=>'application/pdf','bytes'=>$pdf]]];
 }
}
