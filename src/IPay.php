<?php

namespace IPay;

use IPay\Config;
use ReflectionClass;
use IPay\DataBaseManager;
use IPay\Sadad\IPaySadad;
use IPay\Mellat\IPayMellat;
use IPay\Zarinpal\IPayZarinpal;
use IPay\Exceptions\PortNotFoundException;

class IPay
{
    const P_MELLAT = 'mellat';

    const P_SADAD = 'sadad';

    const P_ZARINPAL = 'zarinpal';

    /**
     * @var IPay\Config
     */
    public $config;

    /**
     * @var IPay/DataBaseManager
     */
    protected $db;

    /**
     * Keep current port driver
     *
     * @var mixed
     */
    protected $portClass;

    /**
     * @param string $port
     * @param string $configFile
     *
     * @return void
     */
    public function __construct($port, $configFile = null)
    {
        if (!in_array($port, $this->getSuportedPorts()))
            throw new PortNotFoundException;

        $this->config = new Config($configFile);
        $this->db = new DataBaseManager($this->config);

        switch ($port) {
            case self::P_MELLAT:
                $this->portClass = new IPayMellat($this->config, $this->db);
                break;

            case self::P_SADAD:
                $this->portDriver = new IPaySadad($this->config, $this->db);
                break;

            case self::P_ZARINPAL:
                $this->portDriver = new IPayZarinpal($this->config, $this->db);
                break;
        }

    }

    /**
     * Get suported ports
     *
     * @return array
     */
    public function getSuportedPorts()
    {
        return array(self::P_MELLAT, self::P_SADAD, self::P_ZARINPAL);
    }

    /**
     * Call methods of current driver
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->portClass, $name], $arguments);
    }
}
