<?php

use s9e\TextFormatter\Bundles\Fatdown;

$text      = (substr($_GET['text'], 0, 1000));
$cacheFile = __DIR__ . '/cache/' . sha1($text) . '.json';

header('Content-type: application/json');

if (file_exists($cacheFile))
{
	readfile($cacheFile);
	exit;
}

include __DIR__ . '/include.php';

$xml  = Fatdown::parse($text);
$html = Fatdown::render($xml);
$json = '{"name":"s9e\\\\TextFormatter (Fatdown/PHP)","version":"' . s9e\TextFormatter\VERSION . '","html":' . json_encode($html) . '}';

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

if (!mt_rand(0, 9999))
{
	array_map('unlink', glob(__DIR__ . '/cache/*'));
}

file_put_contents($cacheFile, $json);
file_put_contents(substr($cacheFile, 0, -4) . 'md', $text);
