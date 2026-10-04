<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

namespace Markocupic\SacEventBlogBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Markocupic\SacEventBlogBundle\Migration\EventBlogSacMemberIdToIntMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EventBlogSacMemberIdToIntMigrationTest extends TestCase
{
    /**
     * @dataProvider sacMemberIdProvider
     */
    #[DataProvider('sacMemberIdProvider')]
    public function testToSacMemberId(string $value, int $expected): void
    {
        $this->assertSame($expected, EventBlogSacMemberIdToIntMigration::toSacMemberId($value));
    }

    public static function sacMemberIdProvider(): iterable
    {
        yield 'plain number' => ['123456', 123456];
        yield 'leading zeros' => ['00167400', 167400];
        yield 'text after the number' => ['370883 SAC Pilatus', 370883];
        yield 'no number' => ['keine', 0];
        yield 'empty' => ['', 0];
    }

    public function testRunsOnlyWhileTheColumnIsNoInteger(): void
    {
        $this->assertTrue((new EventBlogSacMemberIdToIntMigration($this->createConnection(new StringType())))->shouldRun());
        $this->assertFalse((new EventBlogSacMemberIdToIntMigration($this->createConnection(new IntegerType())))->shouldRun());
    }

    public function testDoesNotRunWithoutTable(): void
    {
        $this->assertFalse((new EventBlogSacMemberIdToIntMigration($this->createConnection(new StringType(), tableExists: false)))->shouldRun());
    }

    public function testCorrectsValuesReplacesEmptyValuesAndConvertsTheColumn(): void
    {
        $connection = $this->createConnection(new StringType());
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([['id' => '12', 'sacMemberId' => '00167400']])
        ;

        $connection
            ->expects($this->once())
            ->method('update')
            ->with('tl_calendar_events_blog', ['sacMemberId' => '167400'], ['id' => 12])
        ;

        $statements = [];

        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            })
        ;

        $result = (new EventBlogSacMemberIdToIntMigration($connection))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame("UPDATE tl_calendar_events_blog SET sacMemberId = '0' WHERE sacMemberId = ''", $statements[0]);
        $this->assertSame('ALTER TABLE tl_calendar_events_blog MODIFY sacMemberId INT(10) UNSIGNED NOT NULL DEFAULT 0', $statements[1]);
        $this->assertStringContainsString('ID 12: "00167400" → 167400', $result->getMessage());
    }

    private function createConnection(Type $type, bool $tableExists = true): Connection&MockObject
    {
        $column = $this->createMock(Column::class);
        $column->method('getType')->willReturn($type);

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn($tableExists);
        $schemaManager->method('listTableColumns')->willReturn(['sacmemberid' => $column]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        return $connection;
    }
}
