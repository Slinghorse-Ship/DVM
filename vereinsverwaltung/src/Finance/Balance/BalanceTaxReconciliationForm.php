<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Strict original-source wire metadata shared by annual action and error recovery. */
final class BalanceTaxReconciliationForm
{
    public static function source(array $post,int $year):array
    {
        foreach(['balance_year','version','income_sha256','tax_clearing_revision','tax_clearing_sha256','tax_prior_year','tax_prior_revision','tax_prior_sha256']as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Angaben zur Steuerwerteprüfung vollständig prüfen.',422);
        if(!preg_match('/\A[0-9]{4}\z/D',$post['balance_year'])||$post['balance_year']!==(string)$year||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$post['version'])||!preg_match('/\A[a-f0-9]{64}\z/D',$post['income_sha256'])||!preg_match('/\A[1-9][0-9]{0,3}\z/D',$post['tax_clearing_revision'])||(int)$post['tax_clearing_revision']>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$post['tax_clearing_sha256']))throw new DomainException('Ursprüngliche Angaben zur Steuerwerteprüfung vollständig prüfen.',422);
        $prior=null;
        if($post['tax_prior_year']!==''||$post['tax_prior_revision']!==''||$post['tax_prior_sha256']!==''){
            if($post['tax_prior_year']!==(string)($year-1)||!preg_match('/\A[1-9][0-9]{0,3}\z/D',$post['tax_prior_revision'])||(int)$post['tax_prior_revision']>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$post['tax_prior_sha256']))throw new DomainException('Ursprünglichen steuerlichen Vorjahresbezug prüfen.',422);
            $prior=['year'=>$year-1,'revision'=>(int)$post['tax_prior_revision'],'sha256'=>$post['tax_prior_sha256']];
        }
        return ['version'=>(int)$post['version'],'income_sha256'=>$post['income_sha256'],'clearing_source'=>['revision'=>(int)$post['tax_clearing_revision'],'sha256'=>$post['tax_clearing_sha256']],'prior_source'=>$prior];
    }
}
