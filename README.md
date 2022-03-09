# PoolPort
از این پکیج جهت متصل کردن پروژه خود به درگاه‌های بانکی می‌توانید استفاده کنید.

## نصب پکیج

```shell
composer require poolport/poolport:~v3
```

## فایل تنظیمات
فایل `poolport-sample.php` را از کپی کرده و در ریشه پروژه در کنار پوشه `vendor` قرار دهید و مقادیر آن را بر اساس نیاز خود تغییر دهید.

1. اگر میخواید timezone توسط PoolPort تنظیم شود، این مقدار را وارد کنید، در غیر اینصورت این مقدار را خالی رها کنید.
2. مقدار attempts در soap مشخص کننده تعداد تلاش در زمانی که ارتباط با سرور soap برقرار نمیشود، است.
3. تنظیمات database برای اتصال به پایگاه داده است. در صورتی که قسمت create فعال (true) باشد، در هر بار استفاده از PoolPort، پکیج چک میکند که آیا جداول پکیج ایجاد شده است یا خیر، در صورتی که موجود نبودند، خود پکیج به صورت خودکار آنها را نصب میکند. پس توجه داشته باشید که در اولین استفاده از پکیج این گزینه را true کنید.
4. دیگر قسمت‌ها نیز مخصوص هر درگاه است، که در صورت استفاده از هر کدام از آنها، ابتدا تنظیمات آن‌ها را پر کنید.

## انتقال کاربر به درگاه

```php
use PoolPort\PoolPort;

$poolPort = new PoolPort(PoolPort::P_MELLAT);
$poolPort->setGlobalCallbackUrl("https://example.com/callback");
$poolPort->setGlobalUserMobile("09122222222");

try {
    $refId = $poolPort
        ->set(1000)
        ->ready()
        ->refId();

    // Your code here

    return $poolPort->redirect();
} catch (Exception $e) {
    echo $e->getMessage();
}
```

## برگشت کاربر از درگاه

```php
use PoolPort\PoolPort;

try {
    $poolPort = new PoolPort;
    $trackingCode = $poolPort->verify()->trackingCode();

    // User payment verified

    $refId = $poolPort->refId();
    $cardNumber = $poolPort->cardNumber();

    // Your code here

} catch (Exception $e) {
    // User payment not verified

    echo $e->getMessage();
}
```

## لیست درگاه ها فعال
    ملت - P_MELLAT
    ملی - P_SADERAT
    زرین پال - P_ZARINPAL
    پی‌لاین - P_PAYLINE
    جهان پی - P_JAHANPAY
    پارسیان - P_PARSIAN
    صادرات - P_SADERAT
    ایران کیش - P_IRANKISH
    سامان - P_SAMAN
    پی‌ دات آی آر - P_PAY
    جیبیت - P_JIBIT
    آپ - P_AP
    پی پینگ - P_PAYPING
    وندار - P_VANDAR
    شبیه ساز پرداخت - P_SIMULATOR

## لیست درگاه های تست نشده
    پاسارگاد - P_PASARGAD
    بیت پی - BitPay
    آی دی پی - IDPay


مشاهده مستندات در http://poolport.github.io
