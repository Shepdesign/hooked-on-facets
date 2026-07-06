<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use HookedOnFacets\Indexer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for on_post_save() status gating: only 'publish' objects may
 * live in the index. Any other status (draft, pending, private, future,
 * auto-draft, trash) must wipe the object's rows and MUST NOT write index rows,
 * so unpublished data never surfaces through the public /filter endpoint. This
 * mirrors the bulk rebuild path's `post_status = 'publish'` filter — the
 * incremental and bulk paths must agree.
 *
 * The Indexer is `final`, so we drive the real on_post_save() and observe the
 * decision at the $wpdb layer: an unpublished save issues exactly one delete
 * (delete_object) and zero inserts, proving reindex_object() never ran.
 */
final class IndexerSaveHookTest extends TestCase {
    use MockeryPHPUnitIntegration;

    /** @var array<string, int> */
    private array $calls;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Facets are configured, so on_post_save() doesn't early-return.
        Functions\when( 'get_option' )->justReturn( [ [ 'name' => 'brand', 'kind' => 'meta', 'source' => 'brand' ] ] );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_is_post_revision' )->justReturn( false );
        Functions\when( 'wp_is_post_autosave' )->justReturn( false );
        // indexed_post_types() runs its default list through this filter.
        Functions\when( 'apply_filters' )->returnArg( 2 );
        // Reached only if the reindex path runs — its presence in $calls would
        // itself be a failure for the unpublished cases.
        Functions\when( 'get_post_meta' )->alias( function () {
            $this->calls['get_post_meta'] = ( $this->calls['get_post_meta'] ?? 0 ) + 1;
            return [];
        } );

        $this->calls = [];
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        foreach ( [ 'delete', 'insert', 'query' ] as $op ) {
            $wpdb->shouldReceive( $op )->andReturnUsing( function () use ( $op ) {
                $this->calls[ $op ] = ( $this->calls[ $op ] ?? 0 ) + 1;
                return 1;
            } );
        }
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function post( string $status ): \WP_Post {
        return new \WP_Post( [ 'ID' => 42, 'post_status' => $status, 'post_type' => 'product' ] );
    }

    #[DataProvider( 'unpublishedStatuses' )]
    public function test_unpublished_status_wipes_rows_and_never_indexes( string $status ): void {
        ( new Indexer() )->on_post_save( 42, $this->post( $status ), true );

        self::assertSame( 1, $this->calls['delete'] ?? 0, "'{$status}' must delete the object's index rows." );
        self::assertSame( 0, $this->calls['insert'] ?? 0, "'{$status}' must never insert index rows." );
        self::assertSame( 0, $this->calls['query'] ?? 0, "'{$status}' must never bulk-insert index rows." );
        self::assertArrayNotHasKey( 'get_post_meta', $this->calls, "'{$status}' must not enter the reindex/gather path." );
    }

    /** @return array<string, array{0: string}> */
    public static function unpublishedStatuses(): array {
        return [
            'draft'      => [ 'draft' ],
            'pending'    => [ 'pending' ],
            'private'    => [ 'private' ],
            'future'     => [ 'future' ],
            'auto-draft' => [ 'auto-draft' ],
            'trash'      => [ 'trash' ],
        ];
    }
}
