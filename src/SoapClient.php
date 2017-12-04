<?php

namespace PoolPort;

use Closure;
use SoapClient as MainSoapClient;

class SoapClient
{
    /**
     * Main soap class
     *
     * @var \SoapClient
     */
    private $soap;

    /**
     * @var \PoolPort\Config
     */
    protected $config;

    /**
     * @var int
     */
    protected $attempts;

    /**
     * @param string $soapServer
     * @param \PoolPort\Config $config
     * @param array $options
     */
    public function __construct($soapServer, Config $config, $options = array())
    {
        $this->config = $config;

        $this->attempts = (int) $this->config->get('soap.attempts');

        $this->attempt($this->attempts, function() use($soapServer, $options) {
            $this->makeSoapServer($soapServer, $options);
        });
    }

    /**
     * Try soap codes for multiple times
     *
     * @param int $attempts
     * @param \Closure $statements
     *
     * @return void
     */
    protected function attempt($attempts, Closure $statements)
    {
        do {
            try {
                return $statements();
            } catch(\Exception $e) {
                $attempts--;

                if ($attempts == 0)
                    throw $e;
            }
        } while(true);
    }

    /**
     * @param string $soapServer
     *
     * @return void
     */
    protected function makeSoapServer($soapServer, $options)
    {
        $this->soap = new MainSoapClient($soapServer, $options);
    }

    /**
     * @param string $name
     * @param array $arguments
     *
     * @return mixed
     */
    public function __call($name, array $arguments)
    {
        return $this->attempt($this->attempts, function() use($name, $arguments) {
            return call_user_func_array([$this->soap, $name], $arguments);
        });
    }
}
