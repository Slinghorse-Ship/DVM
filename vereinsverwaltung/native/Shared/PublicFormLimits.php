<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use PDO;
/** Shared-server budgets. Only keyed fingerprints are stored, never IPs or email addresses. */
final class PublicFormLimits
{
 public static function membership(PDO $db,string $club,SecretBox $box,string $ip,string $email):bool
 {
  if(!filter_var($ip,FILTER_VALIDATE_IP)||!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
  return Db::atomic($db,static function()use($db,$club,$box,$ip,$email):bool{
   foreach([['membership-ip:'.$ip,8,3600],['membership-email:'.strtolower($email),3,86400],['membership-club',100,3600]]as[$value,$limit,$seconds]){
    $fingerprint=$box->fingerprint($value);$bucket=intdiv(time(),$seconds);
    Db::run($db,'INSERT INTO native_newsletter_rates(club_id,fingerprint,bucket,count) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE count=count+1',[$club,$fingerprint,$bucket]);
    if((int)Db::run($db,'SELECT count FROM native_newsletter_rates WHERE club_id=? AND fingerprint=? AND bucket=? FOR UPDATE',[$club,$fingerprint,$bucket])->fetchColumn()>$limit)return false;
   }
   return true;
  });
 }
}
