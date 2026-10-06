<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Infra;

use ConsultDesk\Infra\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnSemicolonsAndDropsComments(): void
    {
        $sql = <<<'SQL'
            -- leading comment; with a semicolon
            CREATE TABLE a (id INT);
            /* block; comment */
            CREATE TABLE b (id INT) # hash comment;
            ;
            SQL;

        self::assertSame(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'], SqlSplitter::split($sql));
    }

    public function testKeepsSemicolonsInsideQuotesAndIdentifiers(): void
    {
        $sql = "INSERT INTO t VALUES ('a;b', \"c;d\", 'it''s; fine');\nSELECT `odd;name` FROM t;";

        self::assertSame(
            ["INSERT INTO t VALUES ('a;b', \"c;d\", 'it''s; fine')", 'SELECT `odd;name` FROM t'],
            SqlSplitter::split($sql),
        );
    }

    public function testHandlesBackslashEscapesAndATrailingStatementWithoutSemicolon(): void
    {
        self::assertSame(["SELECT 'a\\';b'", 'SELECT 2'], SqlSplitter::split("SELECT 'a\\';b';\nSELECT 2"));
    }

    public function testEmptyInputGivesNoStatements(): void
    {
        self::assertSame([], SqlSplitter::split("  \n-- only a comment\n"));
    }
}
