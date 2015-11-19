<?php

use s9e\TextFormatter\Bundles\Fatdown;

$text = (substr($_GET['text'], 0, 1000));

header('Content-type: application/json');

include __DIR__ . '/vendor/autoload.php';

$xml  = Fatdown::parse($text);
$html = Fatdown::render($xml);
$json = '{"name":"s9e\\\\TextFormatter (Fatdown/PHP)","version":"","html":' . json_encode($html) . '}';

/*
$json = preg_replace_callback(
        '/(?<!\\\\)((?:\\\\\\\\)*)\\\\u([a-f\\d]{4})/',
        function ($m)
        {
                return $m[1] . iconv('UCS-4LE', 'UTF-8', pack('V', hexdec($m[2])));
        },
        $json
);
*/

echo $json;
