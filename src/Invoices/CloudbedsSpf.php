<?php
declare(strict_types=1);
require_once __DIR__.'/../ThirdParty/Spf/autoload.php';

/** Current-DNS SPF for SMTP observations authenticated by the source HMAC.
 * Never consumes arbitrary Authentication-Results, Received-SPF or HELO-only pass.
 */
final class CloudbedsSpf
{
    public static function verify(array $smtp,?\SPFLib\DNS\Resolver $resolver=null): string
    {
        if (array_keys($smtp)!==['ip','mailfrom','helo'] || !is_string($smtp['ip'])
            || !is_string($smtp['mailfrom']) || !is_string($smtp['helo'])
            || filter_var($smtp['ip'],FILTER_VALIDATE_IP)===false || strlen($smtp['mailfrom'])>254
            || !preg_match('/\A[^<>\s@]+@(?:[a-z0-9-]+\.)*cloudbeds\.com\z/i',$smtp['mailfrom'])
            || !preg_match('/\A[a-z0-9.-]{1,253}\z/i',$smtp['helo'])) return 'invalid';
        try {
            $result=(new \SPFLib\Checker($resolver))->check(
                new \SPFLib\Check\Environment($smtp['ip'],$smtp['helo'],$smtp['mailfrom']),
                \SPFLib\Checker::FLAG_CHECK_MAILFROADDRESS)->getCode();
            return in_array($result,['pass','fail','softfail','neutral','none','temperror','permerror'],true)?$result:'temperror';
        } catch (Throwable) { return 'temperror'; }
    }
}
