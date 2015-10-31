<?php

$ips = [];
$cmd = 'ipconfig /all';
exec($cmd, $ipconfig);
$prefix = '   IPv4 Address. . . . . . . . . . . : ';
foreach ($ipconfig as $line) {
	if (substr($line, 0, strlen($prefix)) === $prefix) {
		$ip = substr($line, strlen($prefix));
		$ip = trim(strtr($ip, ['(Preferred)' => '']));
		$ips[] = $ip;
	}
}
$cmd = 'C:\Windows\System32\inetsrv\appcmd.exe list site /xml';
exec($cmd, $output);
$output = implode(PHP_EOL, $output);
$sitesXml = new SimpleXMLElement($output);
$sites = [];
foreach ($sitesXml->SITE as $item) {
	$attributes = $item->attributes();
	$site = [];
	$site['id'] = (string) $attributes['SITE.ID'];
	$site['name'] = (string) $attributes['SITE.NAME'];
	$site['state'] = (string) $attributes['state'];
	$site['hostnames'] = [];
	$site['ips'] = [];
	$site['services'] = [];
	if (empty($site['hostnames']) 
			&& strpos($site['name'], ' ') === false
			&& ($ip = gethostbyname($site['name']))
			&& ($ip !== $site['name'])
		) {
		if (in_array($ip, $ips)) {
			$site['ips'][] = $ip;
		}
		$site['hostnames'][] = $site['name'];
	}

	$bindings = explode(',', (string)$attributes['bindings']);
	foreach ($bindings as $key => $binding) {
		$b = [];
		$bindingParts = explode(':', $binding);
		$bindingInfo = explode('/', $bindingParts[0]);
		$b['type'] = $bindingInfo[0];
		if (!in_array($b['type'], ['http', 'https'])) {
			continue;
		}
		$b['ip'] = $bindingInfo[1];
		if ($b['ip'] === '*') {
			$b['ip'] = false;
		}
		$b['port'] = $bindingParts[1];
		$b['hostname'] = false;
		if (!empty($bindingParts[2])) {
			$b['hostname'] = $bindingParts[2];
		}
		if ($b['hostname'] && !in_array($b['hostname'], $site['hostnames'])) {
			$site['hostnames'][] = $b['hostname'];
		}
		if ($b['ip'] && !in_array($b['ip'], $site['ips'])) {
			$site['ips'][] = $b['ip'];
		}
		if ($b['type'] && !in_array($b['type'], $site['services'])) {
			$site['services'][] = $b['type'];
		}
		$bindings[$key] = $b;
	}
	if (!empty($site['hostnames']) 
			&& empty($site['ips'])
			&& ($ip = gethostbyname($site['hostnames'][0]))
			&& ($ip !== $site['hostnames'][0])
			&& in_array($ip, $ips)
		) {
		$site['ips'][] = $ip;
	}
	$sites[] = $site;
}
$data = ['timestamp' => time(), 'sites' => $sites, 'ips' => $ips];
$filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'sites.json';
file_put_contents($filePath, json_encode($data));
if (file_exists($filePath)) {
	exit(0);
}
exit(1);