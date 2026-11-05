<?php declare(strict_types=1);
/** Two real MySQL connections exercise the canonical archive locks and old review. */
use Kulturhus\Verein\{ContactArchive,Registry};
final class OwnMemberArchiveInterleave extends PDOStatement
{
    protected function __construct(){}
    public static ?Closure $interleave=null;
    public static string $query='';
    public function execute(?array $arguments=null):bool
    {
        $ok=parent::execute($arguments);
        if(self::$interleave&&$this->queryString===self::$query){$callback=self::$interleave;self::$interleave=null;$callback();}
        return $ok;
    }
}
$other=\Kulturhus\Native\TestSupport::db();$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$otherArchive=new ContactArchive($other,'own-list-admin',$archiveKey);$otherRegistry=new Registry($other,'own-list-admin');
$person=$registry->saveContact(null,0,'person','Own concurrent original','','');
$archive->archive($person,1,'Own concurrent original','Own archive lock proof','');
$review=$archive->archived($person);$binding=['version'=>$review['version'],'fingerprint'=>$review['fingerprint'],'name'=>$review['name']];
$blocked=false;$statementClass=$db->getAttribute(PDO::ATTR_STATEMENT_CLASS);
try{
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnMemberArchiveInterleave::class,[]]);
    OwnMemberArchiveInterleave::$query='SELECT * FROM contact_archives WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT';
    OwnMemberArchiveInterleave::$interleave=static function()use($otherArchive,$person,&$blocked):void{try{$otherArchive->restore($person);}catch(DomainException $error){$blocked=$error->getCode()===409;}};
    $archive->restore($person,$binding);
}finally{$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,$statementClass);OwnMemberArchiveInterleave::$interleave=null;}
$check($blocked&&$registry->contact($person)['version']===3,'actual concurrent restore is refused while the canonical original is locked');
$registry->saveContact($person,3,'person','Own subsequent human correction','','');
$allTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$whole=ownOriginalDatabaseHash($db,$allTables);
$deny(fn()=>$archive->restore($person,$binding),'old restore cannot overwrite a later saved human correction');
$check(ownOriginalDatabaseHash($db,$allTables)===$whole,'every complete original row survives a replay after human correction');
$archive->archive($person,4,'Own subsequent human correction','Own new archive cycle','');
$whole=ownOriginalDatabaseHash($db,$allTables);
$deny(fn()=>$archive->restore($person,$binding),'displayed original review cannot restore a later archive cycle');
$check(ownOriginalDatabaseHash($db,$allTables)===$whole,'later encrypted original remains exact when its earlier review is submitted');
$current=$archive->archived($person);$archive->restore($person,['version'=>$current['version'],'fingerprint'=>$current['fingerprint'],'name'=>$current['name']]);
$person2=$registry->saveContact(null,0,'person','Own locked archive source','','');$blocked=false;
try{
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnMemberArchiveInterleave::class,[]]);
    OwnMemberArchiveInterleave::$query='SELECT * FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL FOR UPDATE NOWAIT';
    OwnMemberArchiveInterleave::$interleave=static function()use($otherRegistry,$person2,&$blocked):void{try{$otherRegistry->saveContact($person2,1,'person','Own competing correction','','');}catch(PDOException $error){$blocked=(int)($error->errorInfo[1]??0)===1205;}};
    $archive->archive($person2,1,'Own locked archive source','Own encrypt current original proof','');
}finally{$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,$statementClass);OwnMemberArchiveInterleave::$interleave=null;}
$current=$archive->archived($person2);
$check($blocked&&$current['profile']['name']==='Own locked archive source','canonical archive encrypts its actual locked source and refuses a competing real edit');
$archive->restore($person2,['version'=>$current['version'],'fingerprint'=>$current['fingerprint'],'name'=>$current['name']]);
unset($person,$person2,$other,$otherArchive,$otherRegistry,$review,$binding,$current);
