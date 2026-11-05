<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceReadSnapshot.php';
use Kulturhus\Verein\BalanceReadSnapshot as R;
use Kulturhus\Verein\Tests\MySqlFixture;
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
$db=MySqlFixture::connect();$db->exec('CREATE TABLE snapshot_probe(id int PRIMARY KEY, amount int NOT NULL) ENGINE=InnoDB');$db->exec('INSERT INTO snapshot_probe VALUES(1,10)');
$other=MySqlFixture::existing(MySqlFixture::dsn($db));
$read=static fn(PDO $conn):int=>(int)$conn->query('SELECT amount FROM snapshot_probe WHERE id=1')->fetchColumn();
$lock='verein-write-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);
$free=static function()use($db,$lock):bool{$q=$db->prepare('SELECT IS_FREE_LOCK(?)');$q->execute([$lock]);return (int)$q->fetchColumn()===1;};
// The read helper must establish its own consistent snapshot even if the host
// chooses READ COMMITTED for ordinary transactions. It must not change that default.
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
R::run($db,static function()use($db,$other,$read,$free){
    check($db->inTransaction()&&$read($db)===10,'read snapshot begins at committed source');
    check($free(),'reading a balance does not take the shared writer lock');
    $other->beginTransaction();$other->exec('UPDATE snapshot_probe SET amount=20 WHERE id=1');$other->commit();
    check($read($db)===10&&$read($other)===20,'independent writer completes while reader retains one consistent source');
    try{$db->exec('UPDATE snapshot_probe SET amount=999 WHERE id=1');throw new RuntimeException('Read transaction wrote');}
    catch(PDOException $ex){check(($ex->errorInfo[1]??null)===1792,'MySQL rejects accidental mutation inside owned read snapshot');}
});
check(!$db->inTransaction()&&$free()&&$read($db)===20,'read snapshot ends and next request sees committed update');
check($db->query('SELECT @@transaction_isolation')->fetchColumn()==='READ-COMMITTED','read helper leaves session transaction default unchanged');
$db->beginTransaction();$db->exec('UPDATE snapshot_probe SET amount=30 WHERE id=1');
R::run($db,static function()use($db,$read){check($db->inTransaction()&&$read($db)===30,'caller-owned write transaction is retained');});
check($db->inTransaction()&&$read($other)===20,'nested read does not commit caller-owned work');$db->commit();check($read($other)===30,'normal writing still works after read-only snapshot');
$other->beginTransaction();$other->exec('UPDATE snapshot_probe SET amount=40 WHERE id=1');
check(R::run($db,static fn()=>$read($db))===30,'existing writer does not block plain consistent read');$other->commit();
try{R::run($db,static function(){throw new DomainException('Synthetic failed read');});}catch(DomainException){}
check(!$db->inTransaction()&&$free(),'failed read releases snapshot without a writer lock');
check(R::run($db,static fn()=>$read($db))===40,'subsequent snapshot sees new source after exception');
echo "$n native read snapshot checks passed; two connections to synthetic MySQL only\n";
