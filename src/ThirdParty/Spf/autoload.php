<?php
// Application loader; upstream runtime sources remain unmodified.
spl_autoload_register(static function(string $class): void {
    foreach (['SPFLib\\'=>'spf-lib','IPLib\\'=>'ip-lib','MLocati\\IDNA\\'=>'idna'] as $prefix=>$package) {
        if (!str_starts_with($class,$prefix) || !preg_match('/\A[A-Za-z0-9_\\\\]+\z/',$class)) continue;
        $path=__DIR__.'/'.$package.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
        return;
    }
});
