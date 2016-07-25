<?php

namespace PoolPort\Saderat;

class SaderatException extends \Exception
{
    public static $errors = array(
        '1' => 'وجود خطا در فرمت اطلاعات ارسالی',
        '2' => 'عدم وجود پذیرنده و ترمینال مورد درخواست در سیستم',
        '3' => 'رد درخواست به علت دریافت درخواست توسط آدرس آی‌پی نامعتبر',
        '4' => 'پذیرنده مورد نظر امکان استفاده از سیستم را ندارد.',
        '5' => 'برخورد با مشکل در انجام درخواست مورد نظر',
        '6' => 'خطا در پردازش درخواست',
        '7' => 'بروز خطا در تشخیص اصالت اطلاعات (امضای دیجیتالی نامعتبر است)',
        '8' => 'شماره خرید ارائه شده توسط پذیرنده (CRN) تکراری است.',
    );

    public function __construct($action)
    {
        parent::__construct(self::getError($action));
    }

    public static function getError($action)
    {
        if (isset(self::$errors[$action])) {
            return $action." ".self::$errors[$action];
        } else {
            return self::$errors[1];
        }
    }
}
