<?php namespace Mohsen\IPay;

use SoapClient;
use DateTime;
use PDO;

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
     * Determine request passes
     *
     * @var bool
     */
    protected $requestPass = false;

    /**
     * Refer id
     *
     * @var string
     */
    protected $refId;

    /**
     * Keep ResCode of bpPayRequest response
     *
     * @var int
     */
    protected $payRequestResCode;

    /**
     * Keep saleOrderId
     *
     * @var int
     */
    protected $saleOrderId;

    /**
     * Sale refrence id
     *
     * @var int
     */
    protected $saleReferenceId;

    /**
     * Keep DB conection
     *
     * @var PDO
     */
    protected $dbh;

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
     * @param int $amount
     * @param string $callBackUrl
     * @param string $additionalData
     * @param int|null $orderId
     * @return mixed
     */
    public function sendPayRequest($amount, $callBackUrl, $additionalData = '')
    {
        $soap = new SoapClient($this->serverUrl);
        $dateTime = new DateTime();

        $orderId = $this->newLog();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $orderId,
            'amount' => $amount,
            'localDate' => $dateTime->format('Ymd'),
            'localTime' => $dateTime->format('His'),
            'additionalData' => $additionalData,
            'callBackUrl' => $callBackUrl,
            'payerId' => 0,
        );

        $response = $soap->bpPayRequest($fields);

        $response = explode(',', $response->return);

        if ($response[0] != '0')
            if ($this->debug)
                throw new IPayMellatException($response->return, $this->debugMessagesLanguage);
            else
                return $response->return;

        $this->refId = $response[1];

        $this->editLog($orderId, $this->refId, '', '', $additionalData, 'Start connection to bank.');
        $this->requestPass = true;
        return true;
    }

    /**
     * Get refId
     *
     * @return null|string
     */
    public function getRefId()
    {
        return $this->refId;
    }

    /**
     * Check user payment
     *
     * @return bool
     */
    public function userPayment()
    {
        $this->refId = @$_POST['RefId'];
        $this->payRequestResCode = (int) @$_POST['ResCode'];
        $this->saleOrderId = @$_POST['SaleOrderId'];
        $this->saleReferenceId = @$_POST['SaleReferenceId'];


        if ($this->payRequestResCode == 0)
        {
            return true;
        }
        return false;
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     */
    public function verifyPayment()
    {
        $soap = new SoapClient($this->serverUrl);
        $orderId = $this->newLog();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $orderId,
            'saleOrderId' => $this->saleOrderId,
            'saleReferenceId' => $this->saleReferenceId
        );

        $response = $soap->bpVerifyRequest($fields);

        if ($response->return == '0')
        {
            $this->newLog($this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'User payment done.');
            return true;
        }
        $this->newLog($this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'User not payment.');
        return false;
    }

    /**
     * Send settle request
     *
     * @return bool
     */
    public function settleRequest()
    {
        $soap = new SoapClient($this->serverUrl);
        $orderId = $this->newLog();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $orderId,
            'saleOrderId' => $this->saleOrderId,
            'saleReferenceId' => $this->saleReferenceId
        );

        $response = $soap->bpSettleRequest($fields);

        if ($response->return == '0' || $response->return == '45')
        {
            $this->editLog($orderId, $this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'Settle request done, code = '.$response->return);
            return true;
        }
        $this->editLog($orderId, $this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'Settle request faile, code = '.$response->return);
        return false;
    }

    /**
     * Get ResCode of payRequest method
     *
     * @return int
     */
    public function getPayRequestResCode()
    {
        return $this->payRequestResCode;
    }

    /**
     * Redirect to bank for deposit money
     *
     * @return void
     */
    public function redirectToBank()
    {
        if ($this->requestPass)
        {
            $refId = $this->refId;
            require 'IPayMellatRedirector.php';
        }
    }

    /**
     * Check request passes
     *
     * @return bool
     */
    public function passPayRequest()
    {
        if ($this->requestPass)
            return true;
        else
            return false;
    }

    /**
     * Initialize database connection
     *
     * @param string $host
     * @param string $dbName
     * @param string $usermae
     * @param string $password
     * @return void
     */
    public function setDB($host, $dbName, $username, $password)
    {
        $this->dbh = new PDO("mysql:host=$host;dbname=$dbName;", $username, $password);
    }

    /**
     * Insert new log to table
     *
     * @param string $refId
     * @param string $saleOrderId
     * @param string $saleRefrencesId
     * @param string $AdditionalData
     * @param string $message
     * @return int last inserted id
     */
    public function newLog($refId = '', $saleOrderId = '', $saleRefrencesId = '', $AdditionalData = '', $message = '')
    {
        $date = new DateTime;
        $date = $date->format('Y/m/d H:i:s');

        $stmt = $this->dbh->prepare("INSERT INTO mellat_orders_log (ref_id, sale_order_id, sale_refrences_id, additional_data, message, timestamp) VALUES (:ref_id, :sale_order_id, :sale_refrences_id, :additional_data, :message, :timestamp)");
        $stmt->bindParam(':ref_id', $refId);
        $stmt->bindParam(':sale_order_id', $saleOrderId);
        $stmt->bindParam(':sale_refrences_id', $saleRefrencesId);
        $stmt->bindParam(':additional_data', $AdditionalData);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':timestamp', $date);
        $stmt->execute();

        return $this->dbh->lastInsertId();
    }

    /**
     * Update log in table
     *
     * @param int $id
     * @param string $refId
     * @param string $saleOrderId
     * @param string $saleRefrencesId
     * @param string $AdditionalData
     * @param string $message
     * @return void
     */
    public function editLog($id, $refId = '', $saleOrderId = '', $saleRefrencesId = '', $AdditionalData = '', $message = '')
    {
        $stmt = $this->dbh->prepare("UPDATE mellat_orders_log SET ref_id = :ref_id, sale_order_id = :sale_order_id, sale_refrences_id = :sale_refrences_id, additional_data = :additional_data, message = :message WHERE id = :id");
        $stmt->bindParam(':ref_id', $refId);
        $stmt->bindParam(':sale_order_id', $saleOrderId);
        $stmt->bindParam(':sale_refrences_id', $saleRefrencesId);
        $stmt->bindParam(':additional_data', $AdditionalData);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return $this->dbh->lastInsertId();
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
