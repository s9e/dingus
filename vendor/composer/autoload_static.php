<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitc067be37b53770d841f9312f5e7d910f
{
    public static $prefixLengthsPsr4 = array (
        's' => 
        array (
            's9e\\TextFormatter\\' => 18,
            's9e\\SweetDOM\\' => 13,
            's9e\\RegexpBuilder\\' => 18,
        ),
    );

    public static $prefixDirsPsr4 = array (
        's9e\\TextFormatter\\' => 
        array (
            0 => __DIR__ . '/..' . '/s9e/text-formatter/src',
        ),
        's9e\\SweetDOM\\' => 
        array (
            0 => __DIR__ . '/..' . '/s9e/sweetdom/src',
        ),
        's9e\\RegexpBuilder\\' => 
        array (
            0 => __DIR__ . '/..' . '/s9e/regexp-builder/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitc067be37b53770d841f9312f5e7d910f::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitc067be37b53770d841f9312f5e7d910f::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
