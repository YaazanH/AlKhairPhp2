<?php

namespace Tests\Unit;

use App\Services\MySqlToSqliteBackupConverter;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MySqlToSqliteBackupConverterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/alkhair-converter-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_it_preserves_rows_escaping_keys_defaults_and_auto_increment(): void
    {
        $sql = <<<'SQL'
-- Standard mysqldump setup
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
DROP TABLE IF EXISTS `parents`;
CREATE TABLE `parents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parents_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4;
LOCK TABLES `parents` WRITE;
/*!40000 ALTER TABLE `parents` DISABLE KEYS */;
INSERT INTO `parents` VALUES (0,'صفر'),(7,'أحمد');
/*!40000 ALTER TABLE `parents` ENABLE KEYS */;
UNLOCK TABLES;
CREATE TABLE `children` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `payload` json DEFAULT NULL,
  `binary_value` blob,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `state` enum('open','closed') DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `children_parent_id_foreign` (`parent_id`),
  CONSTRAINT `children_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4;
INSERT INTO `children` VALUES
(2,7,'قال: \'نعم\'; سطر\nثانٍ \\ "اقتباس" -- /* نص */','{"path":"C:\\\\docs","text":"حضور"}',0x000AFF,12.75,1,'open','2026-09-27 12:34:56'),
(3,0,'it''s valid\t\0\Z\b\r\%\_',NULL,_binary 'a\0b',-3.50,0,'closed',NULL);
INSERT INTO `children` (`id`,`parent_id`) VALUES (4,7);
SQL;
        $summary = $this->convert($sql, ['parents', 'children']);
        $this->assertSame(['tables' => 2, 'rows' => 5], $summary);
        $pdo = $this->database();
        $row = $pdo->query('SELECT * FROM children WHERE id=2')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame("قال: 'نعم'; سطر\nثانٍ \\ \"اقتباس\" -- /* نص */", $row['notes']);
        $this->assertSame(['path' => 'C:\\docs', 'text' => 'حضور'], json_decode($row['payload'], true));
        $this->assertSame("\0\x0a\xff", $row['binary_value']);
        $this->assertSame(12.75, $row['amount']);
        $this->assertSame('2026-09-27 12:34:56', $row['created_at']);
        $row = $pdo->query('SELECT * FROM children WHERE id=3')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame("it's valid\t\0\x1a\x08\r\\%\\_", $row['notes']);
        $this->assertNull($row['payload']);
        $this->assertSame("a\0b", $row['binary_value']);
        $row = $pdo->query('SELECT * FROM children WHERE id=4')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, $row['enabled']);
        $this->assertSame('open', $row['state']);
        $pdo->exec("INSERT INTO parents (name) VALUES ('new parent')");
        $this->assertSame('20', $pdo->lastInsertId());
        $pdo->exec('INSERT INTO children (parent_id) VALUES (20)');
        $this->assertSame('50', $pdo->lastInsertId());
        $this->assertSame('parents_name_unique', $pdo->query("SELECT name FROM sqlite_master WHERE name='parents_name_unique'")->fetchColumn());
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('DELETE FROM parents WHERE id=7');
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM children WHERE parent_id=7')->fetchColumn());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function test_table_local_index_names_and_composite_keys_are_preserved(): void
    {
        $this->convert(<<<'SQL'
CREATE TABLE `a` (`x` int NOT NULL, `y` int NOT NULL, PRIMARY KEY (`x`,`y`), KEY `lookup` (`y`));
CREATE TABLE `b` (`id` int NOT NULL, PRIMARY KEY (`id`), KEY `lookup` (`id`));
INSERT INTO `a` VALUES (1,1),(1,2);
INSERT INTO `b` VALUES (1);
SQL);
        $pdo = $this->database();
        $this->assertSame(['lookup', 'b__lookup'], $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%' ORDER BY rowid")->fetchAll(PDO::FETCH_COLUMN));
        $this->expectException(\PDOException::class);
        $pdo->exec('INSERT INTO a VALUES (1,1)');
    }

    #[DataProvider('unsupportedDumpProvider')]
    public function test_it_rejects_unsupported_or_invalid_dumps_and_removes_partial_output(string $sql): void
    {
        try {
            $this->convert($sql);
            $this->fail('The conversion should fail without producing a usable database.');
        } catch (RuntimeException|\PDOException $exception) {
            $this->assertFileDoesNotExist($this->directory.'/converted.sqlite');
        }
    }

    public static function unsupportedDumpProvider(): array
    {
        return [
            'truncated insert' => ['CREATE TABLE `x` (`id` int); INSERT INTO `x` VALUES (1'],
            'truncated string' => ["CREATE TABLE `x` (`v` text); INSERT INTO `x` VALUES ('unfinished);"],
            'unknown SQL' => ["CREATE TABLE `x` (`id` int); ATTACH DATABASE '/tmp/unsafe' AS other;"],
            'executable comment' => ['CREATE TABLE `x` (`id` int); /*!50003 CREATE TRIGGER unwanted BEFORE INSERT ON x SET @x=1 */;'],
            'unknown column type' => ['CREATE TABLE `x` (`v` geometry);'],
            'integer overflow' => ['CREATE TABLE `x` (`id` bigint unsigned); INSERT INTO `x` VALUES (18446744073709551615);'],
            'orphaned foreign key' => ['CREATE TABLE `a` (`id` int, PRIMARY KEY (`id`)); CREATE TABLE `b` (`id` int, CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `a` (`id`)); INSERT INTO `b` VALUES (1);'],
            'duplicate unique key' => ["CREATE TABLE `x` (`v` varchar(50), UNIQUE KEY `value_unique` (`v`)); INSERT INTO `x` VALUES ('same'),('same');"],
            'SQL expression in data' => ["CREATE TABLE `x` (`v` text); INSERT INTO `x` VALUES (load_extension('unwanted'));"],
            'unsafe SQL mode' => ["SET SQL_MODE='NO_BACKSLASH_ESCAPES'; CREATE TABLE `x` (`id` int);"],
            'non UTF8 charset' => ['SET NAMES latin1; CREATE TABLE `x` (`id` int);'],
            'generated column' => ['CREATE TABLE `x` (`id` int, `v` int GENERATED ALWAYS AS (`id` + 1));'],
            'extra values' => ['CREATE TABLE `x` (`id` int); INSERT INTO `x` VALUES (1,2);'],
            'missing values' => ['CREATE TABLE `x` (`id` int, `v` int); INSERT INTO `x` VALUES (1);'],
        ];
    }

    public function test_it_checks_the_manifest_table_inventory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('table inventory');
        $this->convert('CREATE TABLE `x` (`id` int);', ['x', 'missing']);
    }

    public function test_it_will_not_overwrite_an_existing_destination(): void
    {
        file_put_contents($this->directory.'/converted.sqlite', 'keep this file');
        set_error_handler(fn () => true);
        try {
            $this->convert('CREATE TABLE `x` (`id` int);');
            $this->fail('An existing destination must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertSame('keep this file', file_get_contents($this->directory.'/converted.sqlite'));
        } finally {
            restore_error_handler();
        }
    }

    private function convert(string $sql, array $tables = []): array
    {
        file_put_contents($this->directory.'/dump.sql', $sql);

        return (new MySqlToSqliteBackupConverter)->convert($this->directory.'/dump.sql', $this->directory.'/converted.sqlite', $tables);
    }

    private function database(): PDO
    {
        return new PDO('sqlite:'.$this->directory.'/converted.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
