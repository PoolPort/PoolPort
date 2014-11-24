<?php namespace Mohsen\IPay;

use SoapClient;
use DateTime;

/**
 * A class for mellat bank payments
 *
 * @author Mohsen Shafiee
 * @copyright MIT
 */
class IPayMellat extends IPayAbstract implements IPayInterface
{
    /**
     * If true Exceptions executed
     *
     * @var bool
     */
    protected $debug = false;

    /**
     * Address of main SOAP server
     *
     * @var string
     */
    public $serverUrl = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';

    /**
     * Initialize of class
     *
     * @param string $username
     * @param string $password
     * @param int $termId
     * @return void
     */
    public function __construct($username, $password, $termId)
    {
        $this->username = (string) $username;
        $this->password = (string) $password;
        $this->termId = (int) $termId;

        parent::__construct();
    }

    /**
     * Send pay request to server
     *
     * @return bool|IPayMellatException
     */
    public function sendPayRequest($amount, $callBackUrl, $additionalData = '', $orderId = null)
    {
        $soap = new SoapClient($this->serverUrl);
        $dateTime = new DateTime();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => is_null($orderId) ? uniqid(rand(), true) : $orderId,
            'amount' => $amount,
            'localDate' => $dateTime->format('Ymd'),
            'localTime' => $dateTime->format('His'),
            'additionalData' => $additionalData,
            'callBackUrl' => $callBackUrl,
            'payerId' => 0,
        );

        $response = $soap->bpPayRequest($fields);

        if ($response->return != 0)
            if ($this->debug)
                throw new IPayMellatException($response->return, $this->debugMessagesLanguage);
            else
                return $response->return;

        return $response;
    }

    /**
     * Set debug mode for class
     *
     * @param string $messagesLanguage in en and fa
     * @return void
     */
    public function setDebugMode($messagesLanguage = 'en')
    {
        $this->debug = true;
        $this->debugMessagesLanguage = $messagesLanguage == 'en' ? 'en' : 'fa';
    }
}
