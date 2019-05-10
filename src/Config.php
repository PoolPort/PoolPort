<?php

namespace PoolPort;

use PoolPort\Exceptions\ConfigFileNotFoundException;

class Config
{
	/**
	 * User path of config file
	 *
	 * @var string
	 */
	protected $filePath;

	/**
	 * Default path of config file
	 *
	 *   Default path in the project root
	 *   vendor/../poolport.php
	 *
	 * @var string
	 */
	protected $defaultFilePath;

	/**
	 * Config array
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Initialize class
	 *
	 * @param string|null $filePath user path of config file
	 *
	 * @return void
	 */
	public function __construct($filePath = null)
	{
		$this->filePath = $filePath;
		$this->defaultFilePath = realpath(__DIR__.'/../../../..').'/poolport.php';
		$this->load();
	}

	/**
	 * Get config
	 *
	 * @param string $key recursive keys can seperate with '.'
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		$array = $this->config;

		if (is_null($key)) return $default;

		if (isset($array[$key])) return $array[$key];

		foreach (explode('.', $key) as $segment) {
			if (!is_array($array) || !array_key_exists($segment, $array)) {
				return $default;
			}

			$array = $array[$segment];
		}

		return $array;
	}

	/**
	 * Reset a config per request in poolport.php configuration file
	 *
	 * @param string $key
	 * @param mixed $value
	 *
	 * @return void
	 */
	public function set($key, $value)
	{
		$array = &$this->config;

		if (isset($array[$key])) {
			$array[$key] = $value;
		}

		$keys = explode('.', $key);

		while (count($keys) > 1) {
			$key = array_shift($keys);

			$array = &$array[$key];
		}

		$array[array_shift($keys)] = $value;
	}

	/**
	 * Load config file
	 *
	 * @return true|Exception
	 */
	protected function load()
	{
		if (is_file($this->filePath)) {
			$this->config = require $this->filePath;
			return true;
		}
		else if(is_file($this->defaultFilePath)) {
			$this->config = require $this->defaultFilePath;
			return true;
		}

		throw new ConfigFileNotFoundException;
	}
}
