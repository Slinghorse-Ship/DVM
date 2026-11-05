<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{TaxPreparation,TaxPreparationFlow,Permissions};
final class AnnualTaxForm
{
    public const COMPLETE='annual_tax_complete';
    public const ACTIONS=['tax-years','tax-preview','tax-bundle','tax-attachments','tax-rows'];
    public static function requested(array $post):bool{return array_key_exists(self::COMPLETE,$post)||(is_string($post['action']??null)&&in_array($post['action'],self::ACTIONS,true));}
    public static function source(array $query):void{if(($query['view']??null)!=='finance'||($query['tab']??null)!=='tax'||($query['tax_workpapers']??null)!=='1'||array_intersect(['tax_auto','balance','tax_filings','tax_review','tax_saved','tax_resume'],array_keys($query)))throw new DomainException('Jahresunterlagen im ursprünglichen Finanzbereich erneut öffnen.',409);}
    public static function original(array $post):array{return array_intersect_key($post,array_flip(['action','from_year','to_year','activity_report','tax_documents','tax_year_notes','tax_guide','tax_preview_token','annual_tax_attachment_search','annual_tax_attachment_page','annual_tax_browse','annual_tax_rows',self::COMPLETE]));}
    private static function shape(array $post):void
    {
        $fail=static fn()=>throw new DomainException('Jahresangaben und Dokumentauswahl vollständig prüfen.',422);
        $selected=$post['tax_documents']??[];if(!array_is_list($selected)||count($selected)>10)$fail();foreach($selected as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))$fail();
        $notes=$post['tax_year_notes']??[];if(count($notes)>6)$fail();
        $scalars=['record_completeness','record_completeness_note','receivables','liabilities','fixed_assets_book_value','other_assets','valuation_note','reserves_status','funds_use_status','funds_use_note','corrections_status'];
        foreach($notes as$year=>$row){if(!preg_match('/\A[0-9]{4}\z/D',(string)$year)||!is_array($row))$fail();foreach($row as$key=>$value){if(in_array($key,$scalars,true)){if(!is_string($value))$fail();continue;}if(!in_array($key,['reserves','corrections'],true)||!is_array($value)||!array_is_list($value)||count($value)>($key==='reserves'?10:20))$fail();$allowed=$key==='reserves'?['purpose','reason','opening','addition','use','closing']:['sphere','direction','amount','reason'];foreach($value as$item){if(!is_array($item)||array_diff(array_keys($item),$allowed))$fail();foreach($item as$field)if(!is_string($field))$fail();}}}
        $guide=$post['tax_guide']??[];if(array_diff(array_keys($guide),array_keys(\Kulturhus\Verein\TaxDeclarationGuide::questions())))$fail();foreach($guide as$row){if(!is_array($row)||array_diff(array_keys($row),['answer','note']))$fail();foreach($row as$value)if(!is_string($value))$fail();}
    }
    public static function fields(array $post):array
    {
        if(($post[self::COMPLETE]??null)!=='1')throw new DomainException('Jahresformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        if(array_key_exists('annual_tax_rows',$post)){if(!is_string($post['annual_tax_rows'])||isset($post['action'])||array_key_exists('annual_tax_browse',$post)||!preg_match('/\A(?:add:(?:reserves|corrections):[0-9]{4}|remove:(?:reserves|corrections):[0-9]{4}:[0-9]{1,2}|remove-year:[0-9]{4})\z/D',$post['annual_tax_rows']))throw new DomainException('Bearbeitungsschritt der Jahresangaben vollständig prüfen.',422);$post['action']='tax-rows';}
        if(array_key_exists('annual_tax_browse',$post)){if(!is_string($post['annual_tax_browse'])||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$post['annual_tax_browse'])||(int)$post['annual_tax_browse']>1000000||isset($post['action']))throw new DomainException('Nachweissuche und Seitenangabe vollständig prüfen.',422);$post['action']='tax-attachments';$post['annual_tax_attachment_page']=$post['annual_tax_browse'];}
        foreach(['action','from_year','to_year','activity_report']as$key)if(!is_string($post[$key]??null))throw new DomainException('Jahresformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        if(!in_array($post['action'],self::ACTIONS,true))throw new DomainException('Jahresschritt vollständig prüfen.',422);
        foreach(['from_year','to_year']as$key)if(!preg_match('/\A[0-9]{4}\z/D',$post[$key]))throw new DomainException('Ein bis drei gültige Kalenderjahre auswählen.',422);
        $from=(int)$post['from_year'];$to=(int)$post['to_year'];if($from<2000||$to>(int)date('Y')||$to<$from||$to-$from>2)throw new DomainException('Ein bis drei gültige Kalenderjahre auswählen.',422);
        foreach(['tax_documents','tax_year_notes','tax_guide']as$key)if(array_key_exists($key,$post)&&!is_array($post[$key]))throw new DomainException('Jahresangaben und Dokumentauswahl vollständig prüfen.',422);
        foreach(['annual_tax_attachment_search','annual_tax_attachment_page']as$key)if(array_key_exists($key,$post)&&!is_string($post[$key]))throw new DomainException('Nachweissuche und Seitenangabe vollständig prüfen.',422);
        $search=$post['annual_tax_attachment_search']??'';$page=$post['annual_tax_attachment_page']??'1';if(!mb_check_encoding($search,'UTF-8')||strlen($search)>200||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Nachweissuche und Seitenangabe vollständig prüfen.',422);
        self::shape($post);
        if($post['action']==='tax-bundle'&&(!is_string($post['tax_preview_token']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$post['tax_preview_token'])))throw new DomainException('Bitte zuerst eine vollständige Vorschau erstellen.',422);
        return self::original($post);
    }
    public static function handle(PDO $db,string $actor,array $query,array $post,string $csrf,array &$session):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.',403);
        self::source($query);$fields=self::fields($post);try{Permissions::finance($db,$actor,$fields['action']==='tax-bundle');}catch(DomainException$e){throw new DomainException($e->getMessage(),403,$e);}
        $service=new TaxPreparation($db,$actor);
        if($fields['action']==='tax-rows'){
            $parts=explode(':',$fields['annual_tax_rows']);$notes=$fields['tax_year_notes']??[];
            if($parts[0]==='remove-year'){if(!array_key_exists($parts[1],$notes))throw new DomainException('Ursprüngliche Jahresangaben erneut prüfen.',409);unset($notes[$parts[1]]);}
            else{$year=$parts[2];if(!array_key_exists($year,$notes))throw new DomainException('Ursprüngliche Jahresangaben erneut prüfen.',409);$kind=$parts[1];$rows=$notes[$year][$kind]??[];
                if($parts[0]==='add'){if(count($rows)>=($kind==='reserves'?10:20))throw new DomainException('Die höchstmögliche Anzahl von Jahreszeilen ist erreicht.',422);$rows[]=[];}
                else{$index=(int)$parts[3];if(!array_key_exists($index,$rows))throw new DomainException('Ursprüngliche Jahreszeile erneut prüfen.',409);array_splice($rows,$index,1);}
                $notes[$year][$kind]=$rows;
            }
            $fields['tax_year_notes']=$notes;return['fields'=>$fields];
        }
        if(in_array($fields['action'],['tax-years','tax-attachments'],true))return['fields'=>$fields];
        if($fields['action']==='tax-preview')return['fields'=>$fields,'preview'=>TaxPreparationFlow::preview($service,$actor,$fields,$session)];
        $id=TaxPreparationFlow::save($service,$actor,$fields,$session);$key=$session['tax_previews'][$fields['tax_preview_token']]['request_key'];return['fields'=>$fields,'stored'=>$id,'key'=>$key];
    }
}
