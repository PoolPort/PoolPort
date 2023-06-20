<?php

namespace PoolPort\PayStar;

class PayStarErrorException extends \Exception
{
    public static $errors = array(
        -99    =>  'خطای سامانه',
        -98    =>  'تراکنش ناموفق',
        -9     =>  'تراکنش وریفای نشد',
        -8     =>  'تراکنش را نمیتوان وریفای کرد',
        -7     =>  'پارامترهای ارسال شده نامعتبر است',
        -6     =>  'تراکنش قبلا وریفای شده است',
        -5     =>  'شناسه ref_num معتبر نیست',
        -4     =>  'مبلغ بیشتر از سقف مجاز درگاه است',
        -3     =>  'توکن تکراری است',
        -2     =>  'درگاه فعال نیست',
        -1     =>  'درخواست نامعتبر (خطا در پارامترهای ورودی)',
        1      =>  'موفق',
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
            return $action;
        }
    }
}
