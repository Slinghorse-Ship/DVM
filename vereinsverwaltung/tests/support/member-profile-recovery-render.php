<?php declare(strict_types=1);
/** The comparison must disclose every value a deliberate profile retry would replace. */
function ownMemberRecoveryRender(PDO $db,array $profile,array $original,callable $check):void
{
    $globals=[];foreach(['_GET','_POST','_SESSION']as$key)$globals[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];
    $checkbox=str_repeat('a',32);$text=str_repeat('b',32);
    $db->beginTransaction();
    try{
        $rows=[['id'=>$checkbox,'name'=>'Eigene Auswahl <&>','type'=>'checkbox'],['id'=>$text,'name'=>'Eigene Zusatzangabe','type'=>'text']];
        $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at)VALUES(?,'fields',?,1,?)")->execute(['own-list-club',json_encode(['rows'=>$rows],JSON_THROW_ON_ERROR),gmdate(DATE_ATOM)]);
        $profile['version']++;$profile['honorary']=1;$profile['custom_fields']=json_encode([$checkbox=>['name'=>$rows[0]['name'],'type'=>'checkbox','value'=>'1'],$text=>['name'=>$rows[1]['name'],'type'=>'text','value'=>'New saved value']],JSON_THROW_ON_ERROR);
        $_SESSION=['actor'=>'own-list-admin'];$_GET=['view'=>'members','id'=>$profile['id']];$_POST=array_replace($original,['kind'=>'organisation','honorary'=>'0','custom_fields'=>[$checkbox=>'0',$text=>'Own earlier value'],'custom_fields_version'=>'1']);$posted=$_POST;
        $html=(static function(PDO $db,array $profile):string{
            $canWrite=true;$error='Own synthetic parallel change';$managerLanguageLocale='en';$managerLanguageMessages=\Kulturhus\Native\Language\MemberProfileMessages::messages('en');
            ob_start();require dirname(__DIR__,2).'/views/People/Members/profile-original.php';return ob_get_clean();
        })($db,$profile);
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);$comparison=[];
        foreach($xpath->query('//table/tbody/tr')as$row){$cells=$xpath->query('./td',$row);$comparison[$xpath->evaluate('string(./th)',$row)]=array_map(static fn(DOMNode$cell):string=>$cell->textContent,iterator_to_array($cells));}
        $check(($comparison['Profile type']??null)===['Organisation','Person']&&($comparison['Honorary member']??null)===['No','Yes'],'parallel profile comparison discloses both profile type and honorary flag');
        $check(($comparison[$rows[0]['name']]??null)===['No','Yes']&&($comparison[$rows[1]['name']]??null)===['Own earlier value','New saved value'],'parallel profile comparison includes every declared custom value under its escaped human name');
        $button=$xpath->query('//button[@data-form-recovery-current]')->item(0);$versions=$button?json_decode($button->getAttribute('data-recovery-versions'),true,512,JSON_THROW_ON_ERROR):null;
        $check($button&&$button->hasAttribute('hidden')&&$button->getAttribute('data-recovery-target')===$profile['id']&&$versions===['version'=>(string)$profile['version'],'custom_fields_version'=>'1']&&$_POST===$posted,'comparison offers only an explicit same-profile version choice and preserves every submitted value');
    }finally{
        $db->rollBack();foreach($globals as$key=>[$exists,$value])if($exists)$GLOBALS[$key]=$value;else unset($GLOBALS[$key]);
    }
}
