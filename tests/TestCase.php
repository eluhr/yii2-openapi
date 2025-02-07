<?php

namespace tests;

use PHPUnit\Runner\Version;
use Yii;
use yii\db\Connection;
use yii\db\Schema;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\web\Application;
use function array_diff;

class TestCase extends \PHPUnit\Framework\TestCase
{
    public static function assertEqualsCanonicalizing($expected, $actual, string $message = '')
    {
        if ((int)Version::id()[0] >= 8) {
            parent::assertEqualsCanonicalizing($expected, $actual, $message);
        } else {
            self::assertTrue(empty(array_diff($expected, $actual)) && empty(array_diff($actual, $expected)));
        }

    }

    protected function prepareTempDir()
    {
        FileHelper::removeDirectory(__DIR__ . '/tmp/app');
        FileHelper::createDirectory(__DIR__ . '/tmp/app');
        Yii::setAlias('@app', __DIR__ . '/tmp/app');
    }

    protected function mockApplication(?Connection $dbMock, array $extendConfig = []):Application
    {
        $config = ArrayHelper::merge([
            'id' => 'yii2-openapi-test',
            'basePath' => __DIR__ . '/tmp/app',
            'components' => [],
        ],
            $extendConfig);
        if ($dbMock !== null) {
            $config['components']['db'] = $dbMock;
        }
        return new Application($config);
    }

    protected function mockRealApplication($config = [], $appClass = '\yii\console\Application')
    {
        $fileConfig = require __DIR__ . '/config/console.php';
        new $appClass(ArrayHelper::merge($fileConfig, $config));
    }

    protected function mockDbSchemaAsEmpty($driver = 'mysql')
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getTableSchema')->willReturn(null);
        $schema->method('findUniqueIndexes')->willReturn([]);
        $schema->method('quoteValue')->willReturnCallback(function($v) { return "'$v'"; });
        $db = $this->createMock(Connection::class);
        $db->method('getSchema')->willReturn($schema);
        $db->method('getTableSchema')->willReturn(null);
        $db->method('getDriverName')->willReturn($driver);
        return $db;
    }
}
