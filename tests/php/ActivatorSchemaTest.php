<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use HookedOnFacets\Activator;
use PHPUnit\Framework\TestCase;

/**
 * Guards the dbDelta() schema string. dbDelta parses the CREATE TABLE body
 * line by line, so an inline `-- comment` is read as a column named `--`:
 * fine on fresh install (MySQL tolerates it inside CREATE), but on
 * reactivation dbDelta diffs against the existing table and emits a
 * malformed `ALTER TABLE ... ADD COLUMN -- ...` that logs a database error.
 */
final class ActivatorSchemaTest extends TestCase {

    private function schema(): string {
        return Activator::index_table_schema( 'wp_hof_index', 'DEFAULT CHARACTER SET utf8mb4' );
    }

    public function test_schema_contains_no_sql_comments(): void {
        $sql = $this->schema();

        self::assertDoesNotMatchRegularExpression(
            '/^\s*(--|#)/m',
            $sql,
            'dbDelta cannot parse SQL comment lines — it turns them into malformed ALTER statements on reactivation. Keep rationale in PHP comments.'
        );
        self::assertStringNotContainsString( '/*', $sql, 'dbDelta cannot parse block comments either.' );
    }

    public function test_schema_interpolates_table_and_charset(): void {
        $sql = $this->schema();

        self::assertStringContainsString( 'CREATE TABLE wp_hof_index (', $sql );
        self::assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $sql );
    }
}
