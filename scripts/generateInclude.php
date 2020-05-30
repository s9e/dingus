#!/usr/bin/php
<?php

use s9e\TextFormatter\Bundles\Fatdown;

if (php_sapi_name() !== 'cli')
{
	die('Not in CLI');
}

include __DIR__ . '/../vendor/autoload.php';
preload('Parser/*.php');
preload('Parser/AttributeFilters/*.php');
preload('Plugins/Autoemail/Parser.php');
preload('Plugins/Autolink/Parser.php');
preload('Plugins/Escaper/Parser.php');
preload('Plugins/FancyPants/Parser.php');
preload('Plugins/HTMLComments/Parser.php');
preload('Plugins/HTMLElements/Parser.php');
preload('Plugins/HTMLEntities/Parser.php');
preload('Plugins/MediaEmbed/Parser.php');
preload('Plugins/PipeTables/Parser.php');
preload('Plugins/TaskLists/Parser.php');

function preload($path)
{
	$path = __DIR__ . '/../vendor/s9e/text-formatter/src/' . $path;
	foreach (glob($path) as $filepath)
	{
		include_once($filepath);
	}
}

Fatdown::render(Fatdown::parse('https://example.org/img.png
![..](https://example.org/img.png)
[..](https://example.org/img.png)
example@example.org
https://www.youtube.com/watch?v=QH2-TGUlwu4
\\\\
"quotes"
<!-- -->
<b>..</b>
&amp;

- [x] checked

a|b
-|-
c|d'));

$scores = $relations = array();
foreach (filterNamespace(get_declared_classes()) as $className)
{
	$scores[$className] = 0;
	$relations[$className] = array();
	$class = new ReflectionClass($className);
	foreach (filterNamespace($class->getInterfaceNames()) as $interfaceName)
	{
		$scores[$interfaceName] = 0;
		$relations[$className][] = $interfaceName;
	}
	if (method_exists($class, 'getTraitNames'))
	{
		foreach (filterNamespace($class->getTraitNames()) as $traitName)
		{
			$scores[$traitName] = 0;
			$relations[$className][] = $traitName;
		}
	}
	$parentClass = $class->getParentClass();
	if ($parentClass)
	{
		$parentName = $parentClass->getName();
		$relations[$className][] = $parentName;
	}
}

function filterNamespace(array $fqns)
{
	return array_filter(
		$fqns,
		function ($fqn)
		{
			return (strpos($fqn, 's9e\\TextFormatter\\') === 0);
		}
	);
}

do
{
	$continue = false;
	foreach ($relations as $className => $relationNames)
	{
		foreach ($relationNames as $relationName)
		{
			if ($scores[$className] <= $scores[$relationName])
			{
				$scores[$className] = 1 + $scores[$relationName];
				$continue = true;
			}
		}
	}
}
while ($continue);

$classNamesByScore = array();
foreach ($scores as $className => $score)
{
	$classNamesByScore[$score][] = $className;
}
ksort($classNamesByScore);

$rootDir = realpath(__DIR__ . '/../vendor/s9e/text-formatter/src');
$target  = __DIR__ . '/../include.php';
$file    = '<?php';
foreach ($classNamesByScore as $classNames)
{
	sort($classNames);
	foreach ($classNames as $className)
	{
		$filepath = $rootDir . strtr(substr($className, 17), '\\', '/') . '.php';
		$file .= preg_replace('(^\\N++)', '', file_get_contents($filepath));
	}
}

$version = '';
$composer = json_decode(file_get_contents(__DIR__ . '/../composer.lock'));
foreach ($composer->packages as $package)
{
	if ($package->name === 's9e/text-formatter')
	{
		$version = $package->version;
		break;
	}
}

$file .= "\nnamespace s9e\\TextFormatter;\nconst VERSION = " . var_export($version, true) . ";\n";

$file = preg_replace('(\\n\\s*/\\*\\*.*?\\*/)s', '', $file);
$file = preg_replace('(\\n\\s*//.*)', '', $file);

file_put_contents($target, $file);