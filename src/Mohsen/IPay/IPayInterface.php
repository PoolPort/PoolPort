<?php namespace Mohsen\IPay;

/**
 * Interface of pay classes
 *
 * @author Mohsen Shafiee
 * @copyright MIT
 */
interface IPayInterface
{
    //Method for send pay request to server
    public function sendPayRequest($amount, $callBackUrl, $additionalData = null);

    //Method for set debug mode to true
    public function setDebugMode($messagesLanguage = 'en');

    //Method for redirect to bank for perform payment
    public function redirectToBank();
}
