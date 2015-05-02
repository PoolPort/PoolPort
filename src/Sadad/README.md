درگاه سداد ( بانک ملی ) 
----


 قدم اول : تنظیمات اولیه
--
برای استفاده از این درگاه باید ابتدا اطلاعات درگاه خودتون رو به فایل کانفیگ اضافه کنید. برای این کار کد زیر رو به فایل ipay.php اضافه کنید
 
```php
'sadad' => array(
    'merchant'      => 'YOUR_MERCHANT_ID',
    'transactionKey'=> 'YOUR_PRIVATE_TRANSACTION_KEY',
    'terminalId'    => 'YOUR_TERMINAL_ID',
    'callBackUrl'   => 'YOUR_CALLBACK_URL'
)
```
 
قدم دوم : انتقال کاربر به بانک
--

حالا برای منتقل کردن کاربر به درگاه بانک باید از کلاس `IPaySadad` یک شئی جدید ایجاد کنید.

```php
$sadad = new \IPay\Sadad\IPaySadad();
```

- اگر فایل ipay.php در مسیر پیشفرض قرار ندارد باید آدرس آن را به عنوان پارامتراول به کلاس `IPaySadad` پاس بدید.

حالا باید درخواست خودمون رو به درگاه بانک ارسال کنیم تا بانک اطلاعات اولیه رو در اختیارمون قرار بده
برای این کار از متد `sendPayRequest` استفاده می کنیم
این متد دو پارامتر داره که پارامتر اول مبلغ تراکنش هست و پارامتر دوم آدرس بازگشت از بانک (`callback`) که این پارامتر اختیاری است.
- در صورتی که آدرس `callback` رو وارد نکنید از فایل کانفیگ خونده میشه

```php
$result = $sadad->sendPayRequest(1000);
```

خروجی این متد `boolean` هست که اگر `true` بود یعنی درخواست به درستی به بانک ارسال شده و آماده انتقال کاربر به بانک هستیم و اگر ‍`false` بود خطایی در درخواست وجود دارد
که با استفاده از متد `getErrors` می تونید خطای تولید شده رو ببینید.

در صورتی که خروجی متد `true` بود باید با استفاده از متد `redirectToBank` کاربر رو برای خرید به بانک هدایت کنیم

کد زیر نمونه ساده ایی از استفاده از این کلاس است

```php
$sadad = new \IPay\Sadad\IPaySadad();
$result = $sadad->sendPayRequest(1000);

if ($result === false) {
    echo '<pre>';

    print_r($sadad->getErrors());
    echo '</pre>';
    $lastErrorNumber = $sadad->getPayRequestResCode();
} else {
    $refId = $sadad->getRefId();
    # ...
    $sadad->redirectToBank();
}
```

قدم سوم : گرفتن پاسخ از بانک
--

بعد از این که کار کاربر با درگاه بانک تمام شد، بانک کاربر رو به صورت خودکار به آدرسی که ما در مرحله قبل به عنوان `callback` مشخص کردید، ارجاع میده
و ما باید تا نتیجه تراکنش رو از بانک استعلام بگیریم
برای این کار از متد `callback` استفاده می کنیم
خروجی این متد به صورت `boolean` هست که اگر `true` باشه یعنی تراکنش با موفقیت انجام شده و اگر ‍`false` باشه یعنی خطایی در پرداخت رخ داده.

نمونه کد استفاده از متد `callback`

```php
$sadad = new \IPay\Sadad\IPaySadad('./ipay.php');
try {
    $result = $sadad->callback();
    if ($result === false) {
        echo '<h1>Error</h1><pre>';
        print_r($sadad->getErrors());
        echo '</pre>';
        $lastErrorNumber = $sadad->getPayRequestResCode();
    } else {
        echo '<h1>Successful</h1>';
        echo '<h2>Trace Number : ' . $sadad->getTraceNumber() . '</h2>';
    }
}catch (\IPay\Sadad\SadadException $e){
    echo '<h1>Error</h1>'.$e->getMessage();
}
```

- اگر در پرداخت خطایی رخ بده می تونید لیست خطاها رو از متد `getErrors` دریافت کنید.
- کد آخرین خطا رو می تونید از متد `getPayRequestResCode` دریافت کنید.
- در صورت که تراکنش با موفقیت انجام بشه کد رهگیری رو می تونید از متد `getTraceNumber` دریافت کنید.
- حتما برای استفاده از متد `callback` از `try catch` استفاده کنید. چون بعضی از خطا با استثناء `SadadException` تولید میشن و برای مدیریت اونها بهتر هست تا از `try catch` استفاده بشه
