<?php

namespace tests\unit;

use cebe\yii2openapi\generator\ApiGenerator;
use tests\DbTestCase;
use Yii;
use yii\db\mysql\Schema as MySqlSchema;
use yii\db\pgsql\Schema as PgSqlSchema;
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;
use function array_filter;
use function getenv;
use function strpos;

class XDbTypeTest extends DbTestCase
{
    public function testXDbTypeFresh()
    {
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%pristines}}')->execute();
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%newcolumns}}')->execute();
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%editcolumns}}')->execute();

        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%alldbdatatypes}}')->execute();

        $testFile = Yii::getAlias("@specs/x_db_type/mysql/x_db_type_mysql.php");
        $this->runGenerator($testFile, 'mysql');
        // $this->compareFiles($testFile); # TODO

        $this->changeDbToMariadb();
        $testFile = Yii::getAlias("@specs/x_db_type/mysql/x_db_type_mysql.php");
        $this->runGenerator($testFile, 'maria');

        // $this->changeDbToPgsql();
        // $testFile = Yii::getAlias("@specs/x_db_type/pgsql/petstore_x_db_type.php");
        // $this->runGenerator($testFile, 'pgsql');
    }

    public function testXDbTypeSecondaryWithNewColumn() // v2
    {
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%pristines}}')->execute();
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%newcolumns}}')->execute();
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%editcolumns}}')->execute();

        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%alldbdatatypes}}')->execute();

        Yii::$app->db->createCommand()->createTable('{{%newcolumns}}', [
            'id' => 'pk',
            'name' => 'string not null',
        ])->execute();

        $testFile = Yii::getAlias("@specs/x_db_type/mysql/x_db_type_mysql.php");
        $this->runGenerator($testFile, 'mysql');
        // TODO compare changes
        // Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%newcolumns}}')->execute();
    }

    public function testXDbTypeSecondaryWithEditColumn() // v3
    {
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%pristines}}')->execute();
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%newcolumns}}')->execute();
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%editcolumns}}')->execute();

        Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%alldbdatatypes}}')->execute();

        Yii::$app->db->createCommand()->createTable('{{%editcolumns}}', [
            'id' => 'pk',
            'name' => 'varchar(255) not null default "Horse"',
            'tag' => 'text null',
            'string_col' => 'string not null',
            'dec_col' => 'decimal(12, 4)',
            'str_col_def' => 'string default "hi there"',
            'json_col' => 'json',
        ])->execute();

        $testFile = Yii::getAlias("@specs/x_db_type/mysql/x_db_type_mysql.php");
        $this->runGenerator($testFile, 'mysql');
        // TODO compare changes
        // Yii::$app->db->createCommand('DROP TABLE IF EXISTS {{%editcolumns}}')->execute();
    }

    protected function compareFiles(string $testFile)
    {
        $actual = FileHelper::findFiles(Yii::getAlias('@app'), ['recursive' => true]);
        $expected = FileHelper::findFiles(dirname($testFile).'/app', ['recursive' => true]);
        self::assertEquals(
            count($actual),
            count($expected)
        );
        foreach ($actual as $index => $file) {
            $expectedFilePath = $expected[$index];
            self::assertFileExists($file);
            self::assertFileExists($expectedFilePath);

            $this->assertFileEquals($expectedFilePath, $file, "Failed asserting that file contents of\n$file\nare equal to file contents of\n$expectedFilePath");
        }
    }
}
