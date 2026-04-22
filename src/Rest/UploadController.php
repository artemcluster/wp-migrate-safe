<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMigrateSafe\Plugin\Paths;
use WpMigrateSafe\Upload\Exception\InsufficientDiskSpaceException;
use WpMigrateSafe\Upload\Exception\InvalidChunkException;
use WpMigrateSafe\Upload\Exception\UploadException;
use WpMigrateSafe\Upload\UploadSession;
use WpMigrateSafe\Upload\UploadStore;

final class UploadController
{
    private function store(): UploadStore
    {
        return new UploadStore(Paths::uploadsTmpDir(), Paths::backupsDir());
    }

    public function init(WP_REST_Request $request)
    {
        $req = new Request($request);
        $filename = sanitize_file_name($req->getString('filename'));
        $totalSize = $req->getInt('total_size');
        $sha256 = strtolower($req->getString('sha256'));

        if ($filename === '') {
            return new WP_Error('wpms_invalid_filename', 'filename is required', ['status' => 400]);
        }

        if (!preg_match('/\.wpress$/i', $filename)) {
            return new WP_Error('wpms_invalid_extension', 'Only .wpress files are accepted', ['status' => 400]);
        }

        try {
            $session = new UploadSession(
                bin2hex(random_bytes(16)),
                $filename,
                $totalSize,
                WPMS_CHUNK_SIZE,
                $sha256,
                UploadSession::STATUS_PENDING,
                time(),
                []
            );
            $this->store()->create($session);

            return new WP_REST_Response([
                'upload_id' => $session->uploadId(),
                'chunk_size' => $session->chunkSize(),
                'expected_chunks' => $session->expectedChunkCount(),
            ], 200);
        } catch (InsufficientDiskSpaceException $e) {
            return new WP_Error('wpms_disk_full', $e->getMessage(), ['status' => 507]);
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('wpms_invalid_request', $e->getMessage(), ['status' => 400]);
        } catch (UploadException $e) {
            return new WP_Error('wpms_upload_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function chunk(WP_REST_Request $request)
    {
        $req = new Request($request);
        $uploadId = $req->getString('upload_id');
        $chunkIndex = $req->getInt('chunk_index', -1);

        if ($chunkIndex < 0) {
            return new WP_Error('wpms_invalid_chunk_index', 'chunk_index missing', ['status' => 400]);
        }

        // Chunk body is sent as raw POST body (not multipart) to avoid PHP enlarging $_POST.
        $body = $req->getBodyAsString();
        if ($body === '') {
            return new WP_Error('wpms_empty_chunk', 'Empty chunk body', ['status' => 400]);
        }

        try {
            $store = $this->store();
            $session = $store->load($uploadId);
            $session = $store->writeChunk($session, $chunkIndex, $body);
            return new WP_REST_Response([
                'upload_id' => $session->uploadId(),
                'received' => $chunkIndex,
                'received_chunks_count' => count($session->receivedChunks()),
                'expected_chunks' => $session->expectedChunkCount(),
            ], 200);
        } catch (InvalidChunkException $e) {
            return new WP_Error('wpms_invalid_chunk', $e->getMessage(), ['status' => 400]);
        } catch (UploadException $e) {
            return new WP_Error('wpms_upload_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function complete(WP_REST_Request $request)
    {
        $req = new Request($request);
        $uploadId = $req->getString('upload_id');

        try {
            $store = $this->store();
            $session = $store->load($uploadId);
            $finalPath = $store->finalize($session);

            return new WP_REST_Response([
                'upload_id' => $session->uploadId(),
                'filename' => basename($finalPath),
                'size' => filesize($finalPath),
            ], 200);
        } catch (InvalidChunkException $e) {
            return new WP_Error('wpms_chunk_verify_failed', $e->getMessage(), ['status' => 422]);
        } catch (UploadException $e) {
            return new WP_Error('wpms_upload_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function abort(WP_REST_Request $request)
    {
        $req = new Request($request);
        $uploadId = $req->getString('upload_id');

        try {
            $store = $this->store();
            $session = $store->load($uploadId);
            $store->abort($session);
            return new WP_REST_Response(['aborted' => true, 'upload_id' => $uploadId], 200);
        } catch (UploadException $e) {
            return new WP_Error('wpms_abort_failed', $e->getMessage(), ['status' => 500]);
        }
    }
}
