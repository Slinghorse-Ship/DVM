<?php declare(strict_types=1);
require __DIR__.'/../src/Infrastructure/Database/Database.php';
use Kulturhus\Verein\Tests\MySqlFixture;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function fails(callable $fn,string $label):void{try{$fn();}catch(PDOException){check(true,$label);return;}throw new RuntimeException($label);}
$db=MySqlFixture::connect();$db->exec('CREATE TABLE test_rows(id INT PRIMARY KEY,name VARCHAR(200) UNIQUE) ENGINE=InnoDB');
check(!$db->inTransaction(),'idle native connection');
$db->beginTransaction();$db->exec("INSERT INTO test_rows VALUES(1,'original')");check($db->inTransaction(),'explicit transaction active');
fails(fn()=>$db->beginTransaction(),'nested begin rejected without ending outer transaction');
check($db->inTransaction(),'outer transaction retained');$db->commit();
$db->beginTransaction();$db->exec("INSERT INTO test_rows VALUES(2,'discarded')");$db->rollBack();check((int)$db->query('SELECT COUNT(*) FROM test_rows')->fetchColumn()===1,'rollback removes writes');
$db->beginTransaction();$db->exec('SAVEPOINT inner_scope');$db->exec("INSERT INTO test_rows VALUES(2,'nested')");
$db->exec('ROLLBACK TO SAVEPOINT inner_scope');$db->exec('RELEASE SAVEPOINT inner_scope');check($db->inTransaction(),'savepoint retains caller transaction');$db->commit();
$db->beginTransaction();$db->exec("INSERT INTO test_rows VALUES(3,'must rollback')");fails(fn()=>$db->exec("INSERT INTO test_rows VALUES(4,'original')"),'duplicate rejected');
check($db->inTransaction(),'MySQL statement failure still requires explicit rollback');$db->rollBack();check((int)$db->query('SELECT COUNT(*) FROM test_rows')->fetchColumn()===1,'whole failed operation rolled back');
$db->exec('CREATE TABLE child_rows(id INT PRIMARY KEY,parent_id INT,FOREIGN KEY(parent_id) REFERENCES test_rows(id)) ENGINE=InnoDB');
$db->beginTransaction();fails(fn()=>$db->exec('INSERT INTO child_rows VALUES(1,999)'),'native immediate foreign key rejects invalid row');$db->rollBack();
$other=MySqlFixture::existing(MySqlFixture::dsn($db));$db->beginTransaction();$db->exec("INSERT INTO test_rows VALUES(2,'visible after commit')");
check((int)$other->query('SELECT COUNT(*) FROM test_rows')->fetchColumn()===1,'uncommitted data invisible across connections');
$lock='verein-write-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);
$q=$other->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);check((int)$q->fetchColumn()===0,'writer lock held during business transaction');
$db->commit();$q->execute([$lock]);check((int)$q->fetchColumn()===1,'commit releases writer lock');$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
check((int)$other->query('SELECT COUNT(*) FROM test_rows')->fetchColumn()===2,'committed data visible');
$db->beginTransaction();$db->rollBack();$q->execute([$lock]);check((int)$q->fetchColumn()===1,'rollback releases writer lock');$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
$other->exec('SET SESSION TRANSACTION READ ONLY');$other->beginTransaction();fails(fn()=>$other->exec("INSERT INTO test_rows VALUES(3,'blocked')"),'read-only bridge rejects mutation');$other->rollBack();
check(MySqlFixture::integrity($db)==='ok'&&MySqlFixture::foreignKeyErrors($db)===[],'native transaction integrity');
