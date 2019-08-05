#!/usr/bin/php
<?php

use s9e\TextFormatter\Bundles\Fatdown;

if (php_sapi_name() !== 'cli')
{
	die('Not in CLI');
}

include __DIR__ . '/../vendor/autoload.php';

Fatdown::render(Fatdown::parse('*x*'));

$scores = $relations = array();
foreach (get_declared_classes() as $className)
{
	$scores[$className] = 0;
	$relations[$className] = array();
	$class = new ReflectionClass($className);
	foreach ($class->getInterfaceNames() as $interfaceName)
	{
		$scores[$interfaceName] = 0;
		$relations[$className][] = $interfaceName;
	}
	if (method_exists($class, 'getTraitNames'))
	{
		foreach ($class->getTraitNames() as $traitName)
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
		if (strpos($className, 's9e\\TextFormatter\\') !== 0)
		{
			continue;
		}
		$filepath = $rootDir . strtr(substr($className, 17), '\\', '/') . '.php';
		$file .= substr(file_get_contents($filepath), 5);
	}
}

$file = preg_replace('(\\n\\s*/\\*\\*.*?\\*/)s', '', $file);
$file = preg_replace('(\\n\\s*//.*)', '', $file);

file_put_contents($target, $file);