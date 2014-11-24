<?php namespace Mohsen\IPay;

abstract class IPayAbstract
{
    public function __construct()
    {
        date_default_timezone_set('Asia/Tehran');
    }
}
