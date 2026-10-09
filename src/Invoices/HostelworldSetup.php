<?php
declare(strict_types=1);

/** Account-bound authentication configuration, independent of collection validation. */
final class HostelworldSetup
{
    public static function targets(array $account,array $credentials,array $properties): array
    {
        $expected=[2=>'305209',3=>'77759'][(int)($account['id']??0)]??null;
        if ($expected===null || ($account['portal']??'')!=='hostelworld'
            || ($account['auth_method']??'')!=='email'
            || (string)($credentials['hostel_number']??'')!==$expected
            || !array_key_exists($expected,$properties)) return [];
        return [['host'=>'inbox.hostelworld.com','path'=>'/login/']];
    }
}
