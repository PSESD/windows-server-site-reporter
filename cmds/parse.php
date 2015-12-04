<?php

function getCertificateInformation($host) {
	if (!($get = stream_context_create(array("ssl" => array("capture_peer_cert" => TRUE))))) { return false; }
	stream_context_set_option($get, 'ssl', 'verify_host', false);
	stream_context_set_option($get, 'ssl', 'verify_peer', false);
	stream_context_set_option($get, 'ssl', 'verify_peer_name', false);
	if (!($read = stream_socket_client("ssl://{$host}:443", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $get))) { return false; }
	if (!($cert = stream_context_get_params($read))) { return false; }
	if (!isset($cert["options"]["ssl"]["peer_certificate"])) {
		return false;
	}
	if (!($parsed = openssl_x509_parse($cert["options"]["ssl"]["peer_certificate"]))) { return false; }
	if (!isset($parsed['serialNumber']) || !isset($parsed['issuer']) || !isset($parsed['validFrom']) || !isset($parsed['validTo']) || !isset($parsed['subject'])) {
		return false;
	}
	$certificate = [];
	$certificate['id'] = $parsed['serialNumber'];
	$certificate['name'] = $parsed['subject']['CN'];
	$certificate['issuer'] = $parsed['issuer']['O'];
	$certificate['validFrom'] = gmdate('c', $parsed['validFrom_time_t']);
	$certificate['validTo'] = gmdate('c', $parsed['validTo_time_t']);
	return $certificate;
}


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
	$site['url'] = false;
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

		if (!empty($b['hostname']) 
				&& empty($b['ip'])
				&& ($ip = gethostbyname($b['hostname']))
				&& ($ip !== $b['hostname'])
				&& in_array($ip, $ips)
			) {
			$b['ip'] = $ip;
		}
		if ($b['hostname'] && !in_array($b['hostname'], $site['hostnames'])) {
			$site['hostnames'][] = $b['hostname'];
		}
		if ($b['ip'] && !in_array($b['ip'], $site['ips'])) {
			$site['ips'][] = $b['ip'];
		}
		if ($b['type'] && !in_array($b['type'], $site['services'])) {
			if (!isset($site['services'][$b['type']])) {
				$site['services'][$b['type']] = [];
			}
			if ($b['type'] === 'https') {
				$b['certificate'] = false;
				$host = $b['hostname'];
				if (!$host && isset($site['hostnames'][0])) {
					$host = $site['hostnames'][0];
				}
				if (!$host) {
					$host = $b['ip'];
				}
				if ($host && in_array($site['state'], ['Started', 'Unknown'])) {
					$certInfo = getCertificateInformation($host);
					if ($certInfo) {
						$b['certificate'] = $certInfo;
					}
				}
			}
			$site['services'][$b['type']][] = $b;
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
	if (isset($site['hostnames'][0]) && !$site['url']) {
		if (isset($site['services']['https'])) {
			$site['url'] = 'https://' . $site['hostnames'][0];
		} else {
			$site['url'] = 'http://' . $site['hostnames'][0];
		}
	}
	$sites[] = $site;
}
$data = ['timestamp' => time(), 'sites' => $sites, 'ips' => $ips];
$filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'sites.json';
file_put_contents($filePath, json_encode($data));

if (!file_exists($filePath)) {
	exit(1);
}
if (empty($runAfterParse)) {
	echo "Goodbye!";
	exit(0);
}