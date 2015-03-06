<?php namespace Mohsen\IPay\Zarinpal;

class IPayZarinpalException extends \Exception
{
    protected $errors = array(
        'en' => array(
        ),

        'fa' => array(
            -1 => 'اطلاعات ارسال شده ناقص است.',
			-2 => 'IP یا و یا مرچنت کد پذیرنده صحیح نیست.'
        )
    );

    public function __construct($errorId, $language = 'en')
    {
        $this->errorId = $errorId;

        parent::__construct(@$this->errors[$language][$errorId].' #'.$errorId, $errorId);
    }
}
