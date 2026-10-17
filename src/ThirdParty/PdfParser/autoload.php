<?php
// Application adapter; upstream runtime files are unmodified. Native mbstring
// supplies the functionality otherwise provided by symfony/polyfill-mbstring.
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'Smalot\\PdfParser\\') && preg_match('/\A[A-Za-z0-9_\\\\]+\z/',$class)) {
        $file=__DIR__.'/src/'.str_replace('\\','/',$class).'.php';
        if (is_file($file)) require_once $file;
    }
});
