<?php

namespace PoolPort\Tests;

use PoolPort\Config;
use PHPUnit\Framework\TestCase;
use PoolPort\Exceptions\ConfigFileNotFoundException;

class ConfigTest extends TestCase
{
    public function testThrowConfigFileNotFoundException()
    {
        $this->expectException(ConfigFileNotFoundException::class);

        new Config();
    }

    public function testLoadConfigFile()
    {
        new Config(__DIR__."/../poolport-sample.php");

        $this->assertTrue(true);
    }

    public function testCanReadData()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $this->assertSame($config->get("timezone"), "Asia/Tehran");
    }

    public function testCanReadHierarchyData()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $this->assertIsString($config->get("mellat.username"));
    }

    public function testCanReturnDefaultData()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $this->assertSame($config->get("test", "default data"), "default data");
    }

    public function testCanSetData()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $config->set("timezone", "America/Vancouver");

        $this->assertSame($config->get("timezone"), "America/Vancouver");
    }

    public function testCanSetHierarchyData()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $config->set("mellat.username", false);

        $this->assertFalse($config->get("mellat.username"));
    }

    public function testCanSetNewData()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $config->set("my_custom_timezone", "America/NewYork");

        $this->assertSame($config->get("my_custom_timezone"), "America/NewYork");
    }

    public function testCanSetNewHierarchyData()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $config->set("root.my_custom_timezone", "America/NewYork");

        $this->assertSame($config->get("root.my_custom_timezone"), "America/NewYork");
    }

    public function testCanSetNewHierarchyDataWithoutConflict()
    {
        $config = new Config(__DIR__."/../poolport-sample.php");
        $config->set("my_custom_timezone", "America/Vancouver");
        $config->set("root.my_custom_timezone", "America/NewYork");

        $this->assertSame($config->get("my_custom_timezone"), "America/Vancouver");
        $this->assertSame($config->get("root.my_custom_timezone"), "America/NewYork");
    }
}