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
		echo json_encode($this->getData());
	}

	public function getData()
	{
		$data = ['id' => $this->config['id'], 'timestamp' => time(), 'sites' => false, 'ips' => false];
		$filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'sites.json';
		if (file_exists($filePath)) {
			$sites = json_decode(file_get_contents($filePath), true);
			$age = false;
			if (isset($sites['ips'])) {
				$data['ips'] = $sites['ips'];
			}
			if (isset($sites['timestamp'])) {
				$age = (time() - $sites['timestamp']) / 60;
				if ($age > 60) {
					$age = false;
				}
			}
			if ($age && !empty($sites['sites'])) {
				$data['sites'] = [];
				foreach ($sites['sites'] as $site) {
					$site['id'] = md5($this->config['id'] .'.'. $site['id']);
					$data['sites'][] = $site;
				}
			}
		}
		return $data;
	}
}
?>