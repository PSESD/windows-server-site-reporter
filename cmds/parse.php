<?php

function getCertificateInformation($host) {
	return false;
	if($fp = tmpfile()) {
		$ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL,"https://{$host}/");
	    curl_setopt($ch, CURLOPT_STDERR, $fp);
	    curl_setopt($ch, CURLOPT_CERTINFO, 1);
	    curl_setopt($ch, CURLOPT_VERBOSE, 1);
	    curl_setopt($ch, CURLOPT_HEADER, 1);
	    curl_setopt($ch, CURLOPT_NOBODY, 1);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	    $result = curl_exec($ch);
	    curl_errno($ch)==0 or die("Error:".curl_errno($ch)." ".curl_error($ch));
	    fseek($fp, 0);//rewind
	    $str='';
	    while(strlen($str.=fread($fp,8192))==8192);
	    $lines = preg_split('/\R/', $str);
	    $payAttention = false;
	    $certificates = $info = [];
	    $currentCertificate = false;
	    echo $str;exit;
	    foreach ($lines as $line) {
	    	$line = trim($line);
	    	if (substr($line, 0, 2) === "* ") {
	    		$line = substr($line, 2);
	    	}
	    	if ($line === '-----BEGIN CERTIFICATE-----') {
	    		$currentCertificate = $line . PHP_EOL;
	    	} elseif ($currentCertificate !== false) {
	    		if (substr($line, -1) === '*') {
	    			$currentCertificate .= substr($line, 0, strlen($line)-1) . PHP_EOL . '-----END CERTIFICATE-----';
	    			echo $currentCertificate;exit;
	    			$currentCertificate = trim($currentCertificate);
	    			$certinfo = @openssl_x509_parse($currentCertificate);
	    			$info[] = ['id' => $certinfo['name']];
	    			$currentCertificate = false;
	    		}elseif ($line === '-----END CERTIFICATE-----') {
	    			$currentCertificate .= $line;

	    			$currentCertificate = trim($currentCertificate);
	    			$certinfo = @openssl_x509_parse($currentCertificate);
	    			$info[] = ['id' => $certinfo['name']];
	    			$currentCertificate = false;
	    		} else {
	    			$currentCertificate .= $line . PHP_EOL;
	    		}
	    	}
	    }
	    var_dump($info);
	    fclose($fp);
	}
	exit;
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
				if (!$host) {
					$host = $b['ip'];
				}
				if ($host) {
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