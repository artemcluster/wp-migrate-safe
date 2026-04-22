<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * End-to-end test of the upload REST endpoints against a real WordPress runtime.
 *
 * Run with:   ./vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
final class UploadControllerTest extends WP_UnitTestCase
{
    private int $adminUserId;
    protected \WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();
        /** @var WP_REST_Server $wp_rest_server */
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init');

        $this->adminUserId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminUserId);
    }

    public function testFullChunkedUploadRoundTrip(): void
    {
        $payload = random_bytes(12 * 1024); // 12 KB
        $sha = hash('sha256', $payload);
        $filename = 'integration-' . uniqid() . '.wpress';

        // 1. init
        $initReq = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/init');
        $initReq->set_param('filename', $filename);
        $initReq->set_param('total_size', strlen($payload));
        $initReq->set_param('sha256', $sha);

        $initRes = $this->server->dispatch($initReq);
        $this->assertSame(200, $initRes->get_status(), json_encode($initRes->get_data()));

        $uploadId = $initRes->get_data()['upload_id'];
        $chunkSize = $initRes->get_data()['chunk_size'];

        // 2. chunks — we artificially force small chunks by sending multiple slices
        //    (real chunk_size from init is 5 MB; payload is only 12 KB so expected_chunks = 1)
        $expected = $initRes->get_data()['expected_chunks'];
        $this->assertSame(1, $expected);

        $chunkReq = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/chunk');
        $chunkReq->set_param('upload_id', $uploadId);
        $chunkReq->set_param('chunk_index', 0);
        $chunkReq->set_body($payload);

        $chunkRes = $this->server->dispatch($chunkReq);
        $this->assertSame(200, $chunkRes->get_status(), json_encode($chunkRes->get_data()));

        // 3. complete
        $completeReq = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/complete');
        $completeReq->set_param('upload_id', $uploadId);

        $completeRes = $this->server->dispatch($completeReq);
        $this->assertSame(200, $completeRes->get_status(), json_encode($completeRes->get_data()));

        $finalFilename = $completeRes->get_data()['filename'];
        $finalPath = \WpMigrateSafe\Plugin\Paths::backupsDir() . '/' . $finalFilename;
        $this->assertFileExists($finalPath);
        $this->assertSame($sha, hash_file('sha256', $finalPath));

        unlink($finalPath);
    }

    public function testUnauthorizedRequestIsRejected(): void
    {
        wp_set_current_user(0);

        $req = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/init');
        $req->set_param('filename', 'x.wpress');
        $req->set_param('total_size', 100);
        $req->set_param('sha256', str_repeat('a', 64));

        $res = $this->server->dispatch($req);
        $this->assertSame(401, $res->get_status());
    }

    public function testRejectsNonWpressExtension(): void
    {
        $req = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/init');
        $req->set_param('filename', 'not-allowed.zip');
        $req->set_param('total_size', 100);
        $req->set_param('sha256', str_repeat('a', 64));

        $res = $this->server->dispatch($req);
        $this->assertSame(400, $res->get_status());
    }

    public function testRejectsHashMismatchAtComplete(): void
    {
        $payload = 'real payload';
        $wrongSha = hash('sha256', 'different');

        $init = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/init');
        $init->set_param('filename', 'mismatch.wpress');
        $init->set_param('total_size', strlen($payload));
        $init->set_param('sha256', $wrongSha);

        $initRes = $this->server->dispatch($init);
        $this->assertSame(200, $initRes->get_status());
        $uploadId = $initRes->get_data()['upload_id'];

        $chunk = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/chunk');
        $chunk->set_param('upload_id', $uploadId);
        $chunk->set_param('chunk_index', 0);
        $chunk->set_body($payload);
        $this->server->dispatch($chunk);

        $complete = new WP_REST_Request('POST', '/wp-migrate-safe/v1/upload/complete');
        $complete->set_param('upload_id', $uploadId);
        $res = $this->server->dispatch($complete);

        $this->assertSame(422, $res->get_status());
    }
}
