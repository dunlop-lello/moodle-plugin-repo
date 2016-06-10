<?php

$api = "https://download.moodle.org/api/1.3/pluglist.php";
$viewpluginpage = "https://moodle.org/plugins/view.php?id=";
$queries = array(
    'title' => '//div[@class="plugin-heading"]//h2[@class="title"]/a',
    'maintainers' => '//div[@class="maintainedby"]/span[@           class="name"]',
    'description' => '//div[@class="shortdescription"]',
);
$corebase = "https://download.moodle.org/download.php/direct";
$packagesfile = __DIR__ . '/packages.json';

$skipcore = in_array('--skip-core', $argv);
$addcorerequires = in_array('--add-core-requires', $argv);

function cache_or_download($url)
{
    $cachefile = __DIR__.'/cache/'.basename($url);
    if (!file_exists($cachefile)) {
        echo "No $cachefile; downloading $url".PHP_EOL;
        $data = file_get_contents($url);
        file_put_contents($cachefile, $data);
    } else {
        echo "Using $cachefile for $url".PHP_EOL;
        $data = file_get_contents($cachefile);
    }
    return $data;
}

function scrape($html, $queries)
{
    $result = new stdClass();
    @$doc = DOMDocument::loadHtml($html);
    $xpath = new DOMXPath($doc);
    foreach ($queries as $key => $query)
    {
        $result->$key = array();
        $nodeList = $xpath->query($query);
        for ($i = 0; $i < $nodeList->length; $i++)
        {
            $result->$key[] = $nodeList->item($i)->textContent;
        }
    }
    return $result;
}

if (!$pluginlist = json_decode(cache_or_download($api))) {
	die("Unable to read plugin list");
}

$repo = new stdClass();
$repo->packages = [];

foreach ($pluginlist->plugins as $plugin) {
  if (empty($plugin->component)) {
      // We can't do anything without a component
      continue;
  }
  list($plugintype, $pluginname) = explode('_', $plugin->component, 2);
    $html = cache_or_download($viewpluginpage.$plugin->id);
    $scraped = scrape($html, $queries);
	$package = new stdClass();
	$package->name = 'moodle-plugin-db/' . $plugin->component;
	$package->description = $scraped->description[0];
    $package->authors = array();
    foreach ($scraped->maintainers as $maintainer)
    {
        $package->authors[] = array("name" => $maintainer);
    }
	$packageversions = [];
	foreach ($plugin->versions as $version) {
		$versionpackage = clone($package);
		$versionpackage->version = $version->version;
		$versionpackage->type = 'moodle-' . $plugintype;
		$dist = new stdClass();
		$dist->url = $version->downloadurl;
		$dist->type = 'zip';
		$versionpackage->dist = $dist;
		$versionpackage->require = ['composer/installers' => '*'];
		if ($addcorerequires) {
    		$supportedmoodles = [];
    		foreach ($version->supportedmoodles as $supportedmoodle) {
    		    $supportedmoodles[] = $supportedmoodle->release . '.*';
    		}
    		$versionpackage->require['moodle/moodle'] = implode('||', $supportedmoodles);
		}
		$versionpackage->extra = ['installer-name' => $pluginname];
    	$packageversions[$version->version] = $versionpackage;
	}
	$repo->packages[$package->name] = $packageversions;
}

// Finish if we don't want core
if ($skipcore) {
	file_put_contents($packagesfile, json_encode($repo));
	exit(0);
}

// composer.json was only introduced in 2.3.4, so doing create-project with
// earlier versions will confuse composer, although the core project will
// still be created.
$coremaxversions = [
    '2.9' => 1,
    '2.8' => 7,
    '2.7' => 9,
    '2.6' => 11,
    '2.5' => 9,
    '2.4' => 11,
    '2.3' => 11,
    '2.2' => 11,
    '2.1' => 10,
    '2.0' => 10,
    '1.9' => 19,
    '1.8' => 14,
    '1.7' => 7,
    '1.6' => 9
];

$package = new stdClass();
$package->name = 'moodle/moodle';
$packageversions = [];
foreach ($coremaxversions as $major => $max) {
    for ($i = $max; $i >= 0; $i--) {
        $versionno = $major . '.' . $i;
        $directory = 'stable'. str_replace('.', '', $major);
        if ($i == '0') {
            // the 0 is not part of the filename
            $filename = "moodle-$major.zip";
        } else {
            $filename = "moodle-$versionno.zip";
        }
        $versionpackage = clone($package);
        $versionpackage->version = $versionno;
        $versionpackage->type = 'project';
        $dist = new stdClass();
        $dist->url = $corebase . "/$directory/$filename";
        $dist->type = 'zip';
        $versionpackage->dist = $dist;
        $versionpackage->require = ['composer/installers' => '*'];
        $packageversions[$versionno] = $versionpackage;
    }
}
$repo->packages[$package->name] = $packageversions;

file_put_contents($packagesfile, json_encode($repo));
