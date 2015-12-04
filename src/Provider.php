<?php
namespace psesd\serverReporter;

class Provider
{
	protected $config;

	public function __construct($config = [])
	{
		$this->config = $config;
	}
	public function provide()
	{
		header("Content-type: application/json");
		$data = $this->getData(false);
		if (!$data) {
			return false;
		}
		echo json_encode($data);
	}

	public function log($message)
	{
		$logFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'provider.log';
		$message = date("F d, Y G:i:s") .': ' . $message . PHP_EOL;
		file_put_contents($logFile, $message, FILE_APPEND);
	}

	public function push()
	{
		if (!isset($this->config['push'])) {
			return false;
		}
		$errors = false;
		$data = $this->getData(true);
		if (!$data) {
			$this->log("Push failed because the data was stale");
			return false;
		}
		foreach ($this->config['push'] as $push) {
			$headers = ['Content-Type: application/x-www-form-urlencoded'];
			if (!empty($push['key'])) {
				$headers[] = 'X-Api-Key: ' . $push['key'];
			}
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $push['url']);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); 
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300); 
			curl_setopt($ch, CURLOPT_TIMEOUT, 300);
			//execute post
			$result = $rawResult = curl_exec($ch);
			if (substr($result, 0, 1) === '{' && ($result = json_decode($result, true)) && !empty($result['status']) && $result['status'] === 'accepted') {
				// $this->log("Push to {$push['url']} succeeded");
			} else {
				$this->log("Push to {$push['url']} failed ({$rawResult})");
				$errors = true;
			}
			//close connection
			curl_close($ch);
		}
		return !$errors;
	}

	public function getLocalSensorData()
	{
		$sensors = [];
		$sensors['disk-space-C'] = $this->getDiskSpaceSensor('C');
		$sensors['disk-space-D'] = $this->getDiskSpaceSensor('D');
		return $sensors;
	}

	protected function getDiskSpaceSensor($drive)
	{
		$sensor = [
			'class' => 'canis\sensors\local\DynamicData',
			'id' => 'disk-space-' . $drive,
			'name' => 'Free Disk Space on ' . $drive,
			'dataValuePostfix' => '%'
		];
		$payload = [];
		$totalSpace = disk_total_space($drive .':');
		$freeSpace = disk_free_space($drive .':');
		$freePercent = ($freeSpace/$totalSpace)*100;
		if ($freePercent < 15) {
			$payload['state'] = 'low';
		} else {
			$payload['state'] = 'normal';
		}
		// $payload['total'] = $totalSpace;
		// $payload['free'] = $freeSpace;
		$payload['dataValue'] = round($freePercent, 1);
		$sensor['payload'] = $payload;
		return $sensor;
	}

	public function getData($isPush = false)
	{
		$data = ['timestamp' => time(), 'earliestNextCheck' => time(), 'provider' => null];
		$data['earliestNextCheck'] = time() + 60;
		if ($isPush) {
			$providerClass = 'canis\sensors\providers\PushProvider';
		} else { 
			$providerClass = 'canis\sensors\providers\PullProvider';
		}
		$data['provider'] = [
			'class' => $providerClass,
			'id' => $this->config['id'] .'',
			'name' => $this->config['name'],
			'meta' => [],
			'sites' => [],
			'servers' => []
		];

		$data['provider']['servers']['self'] = [
			'class' => 'canis\sensors\servers\WindowsServer',
			'id' => $this->config['id'],
			'name' => $this->config['name'],
			'meta' => [],
			'resources' => [],
			'services' => [],
			'sensors' => $this->getLocalSensorData()
		];
		$data['provider']['servers']['self']['meta']['PHP Version'] = phpversion();

		$data['provider']['servers']['self']['services']['http'] = [
			'class' => 'canis\sensors\services\HttpService'
		];
		$data['provider']['servers']['self']['services']['https'] = [
			'class' => 'canis\sensors\services\HttpsService'
		];

		$filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'sites.json';
		if (file_exists($filePath)) {
			$sites = json_decode(file_get_contents($filePath), true);
			$age = false;
			if (isset($sites['ips'])) {
				foreach ($sites['ips'] as $ip) {
					$data['provider']['servers']['self']['resources'][] = [
						'class' => 'canis\sensors\resources\IP',
						'ip' => $ip
					];
				}
			}
			if (isset($sites['timestamp'])) {
				$age = (time() - $sites['timestamp']) / 60;
				if ($age > 60) {
					$age = false;
				}
			}
			if ($age === false) {
				return false;
			}
			if (!empty($sites['sites'])) {
				foreach ($sites['sites'] as $site) {
					$type = 'Dynamic';
					$base = [];
					if (isset($this->config['siteConfig'][$site['id']])) {
						$base = $this->config['siteConfig'][$site['id']];
					}
					if (!empty($base['ignore'])) {
						continue;
					}
					if (isset($base['type'])) {
						$type = $base['type'];
						unset($base['type']);
					}
					$id = $site['name'];
					if (substr($id, 0, 1) === '_') {
						continue;
					}
					if (empty($id)) {
						$id = md5($this->config['id'] .'.'. $site['id']);
					}
					$siteConfig = array_merge([
						'class' => 'canis\sensors\sites\\' . $type,
						'id' => $id,
						'name' => $site['name'],
						'url' => $site['url'],
						'serviceReferences' => [],
						'meta' => [
							'IIS Site ID' => $site['id']
						]
					], $base);
					// 'ips' => $site['ips'],
					// 'hostnames' => $site['hostnames'],

					foreach ($site['services'] as $service => $bindings) {
						$n = 0;
						$serviceConfig = [];
						$serviceConfig['class'] = 'canis\sensors\serviceReferences\ServiceBindings';
						$serviceConfig['service'] = $service;
						$serviceConfig['object'] = $this->config['id'];
						$serviceConfig['objectType'] = 'server';
						$serviceConfig['resourceReferences'] = [];
						$serviceConfig['bindings'] = [];

						foreach ($bindings as $binding) {
							if (!empty($binding['certificate'])) {
								$id = 'certificate.'.$binding['certificate']['id'];
								if (!isset($data['provider']['servers']['self']['resources'][$id])) {
									$certificateState = 'normal';
									$certificateExpires = strtotime($binding['certificate']['validTo']);
									$certificateInDanger = strtotime("+1 month");
									if ($certificateExpires < $certificateInDanger) {
										$certificateState = 'error';
									}
									$sensor = [
										'class' => 'canis\sensors\local\CertificateExpiration',
										'id' => 'certificate-expiration',
										'name' => 'Certificate Expiration',
										'payload' => [
											'state' => $certificateState,
											'expireDate' => $binding['certificate']['validTo']
										]
									]; 
									$data['provider']['servers']['self']['resources'][$id] = [
										'class' => 'canis\sensors\resources\Certificate',
										'id' => $id,
										'name' => $binding['certificate']['name'],
										'meta' => [
											'Issuer' => $binding['certificate']['issuer'],
											'Issue Date' => date("F j, Y g:i:sa T", strtotime($binding['certificate']['validFrom'])),
											'Expiration Date' => date("F j, Y g:i:sa T", strtotime($binding['certificate']['validTo'])),
										],
										'sensors' => [
											'certificate-expiration' => $sensor
										]
									];
								}
								$serviceConfig['resourceReferences'][$id] = [
									'class' => 'canis\sensors\resourceReferences\SharedResource',
									'object' => $this->config['id'],
									'objectType' => 'server',
									'resource' => $id
								];
							}
							if (!empty($binding['ip'])) {
								$reference = [];
								if ($binding['type'] === 'https') {
									$reference['class'] = 'canis\sensors\resourceReferences\DedicatedResource';
								} else {
									$reference['class'] = 'canis\sensors\resourceReferences\SharedResource';
								}
								$reference['resource'] = 'ip.'.$binding['ip'];
								$reference['object'] = $this->config['id'];
								$reference['objectType'] = 'server';
								$serviceConfig['resourceReferences'][$reference['resource']] = $reference;
							}
							$serviceConfig['bindings'][] = $binding;
						}
						$siteConfig['serviceReferences'][$service] = $serviceConfig;
					}
					unset($binding['certificate']);
					if (isset($this->config['databaseServer']) && !isset($siteConfig['serviceReferences']['mysql']) && !in_array($siteConfig['class'], ['canis\sensors\sites\Static', 'canis\sensors\sites\SensorProvider'])) {
						$siteConfig['serviceReferences']['mysql'] = [
							'class' => 'canis\sensors\serviceReferences\ServiceConnection',
							'object' => $this->config['databaseServer'],
							'objectType' => 'server',
							'name' => 'Database',
							'service' => 'mysql'
						];
					}
					$data['provider']['sites'][] = $siteConfig;
				}
			}
		}
		return $data;
	}
}
?>