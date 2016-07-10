<?php

namespace PoolPort\Pasargad;

class PasargadException extends \Exception
{
    public static $errors = array(
        'NOTRANSACTON' => 'هیچ پرداختی انجام نشد.',
        'GENERAL' => 'خطایی رخ داد'
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
            return self::$errors['GENERAL'];
        }
    }
}
