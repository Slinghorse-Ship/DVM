<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** An exact historical client selection, never an approval or mutation. */
final class BalancePeriodTaxReconciliationForm
{
    public static function source(array $post):array
    {
        $keys=['period_id','version','income_sha256','period_tax_area_revision','period_tax_area_sha256','period_tax_prior_kind','period_tax_prior_id','period_tax_prior_revision','period_tax_prior_sha256'];
        foreach($keys as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Angaben zu den datierten Steuerwerten vollständig prüfen.',422);
        if(!preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$post['period_id'])||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$post['version'])||!preg_match('/\A[a-f0-9]{64}\z/D',$post['income_sha256'])||!preg_match('/\A(?:[1-9][0-9]{0,2}|1000)\z/D',$post['period_tax_area_revision'])||!preg_match('/\A[a-f0-9]{64}\z/D',$post['period_tax_area_sha256']))throw new DomainException('Ursprüngliche Angaben zu den datierten Steuerwerten vollständig prüfen.',422);
        $p=[$post['period_tax_prior_kind'],$post['period_tax_prior_id'],$post['period_tax_prior_revision'],$post['period_tax_prior_sha256']];$prior=null;
        if($p!==['','','','']){
            if(!in_array($p[0],['period','calendar'],true)||!preg_match($p[0]==='period'?'/\A[0-9]{8}-[0-9]{8}\z/D':'/\A[0-9]{4}\z/D',$p[1])||!preg_match('/\A(?:[1-9][0-9]{0,2}|1000)\z/D',$p[2])||!preg_match('/\A[a-f0-9]{64}\z/D',$p[3]))throw new DomainException('Ursprünglichen steuerlichen Vorzeitraum vollständig auswählen.',422);
            $prior=['kind'=>$p[0],'id'=>$p[1],'revision'=>(int)$p[2],'sha256'=>$p[3]];
        }
        return ['period_id'=>$post['period_id'],'version'=>(int)$post['version'],'income_sha256'=>$post['income_sha256'],'area_source'=>['revision'=>(int)$post['period_tax_area_revision'],'sha256'=>$post['period_tax_area_sha256']],'prior_source'=>$prior];
    }
}
