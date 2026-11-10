<?php
// Authenticated job API. Browser writes also pass the same-origin check in check_csrf().

require_once dirname(__DIR__) . '/src/bootstrap.php';

admin_auth();

$method = $_SERVER['REQUEST_METHOD'];
$path = preg_replace('~^/api~', '', request_path());

try {
    if ($method === 'GET' && $path === '/jobs') {
        $page = list_jobs([
            'limit' => array_key_exists('limit', $_GET) ? $_GET['limit'] : 200,
            'offset' => $_GET['offset'] ?? 0,
            'q' => $_GET['q'] ?? '',
            'collection' => $_GET['collection'] ?? '',
            'sort' => $_GET['sort'] ?? 'created',
            'dir' => $_GET['dir'] ?? 'desc',
        ]);
        json_out([
            'jobs' => array_map('public_job', $page['jobs']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
            'counts' => job_counts(),
        ]);
    }

    if ($method === 'GET' && $path === '/imports') {
        json_out(['files' => list_import_files()]);
    }

    if ($method === 'GET' && $path === '/collections' && !array_key_exists('q', $_GET)) {
        json_out(['collections' => list_collections()]);
    }

    if ($method === 'GET' && $path === '/collections') {
        json_out(['collections' => search_collections((string) $_GET['q'])]);
    }

    if ($method === 'GET' && preg_match('~^/collections/([A-Za-z0-9][A-Za-z0-9._-]*)$~', $path, $matches)) {
        $collection = list_collection($matches[1]);
        if (!$collection) {
            fail('Not found', 404);
        }
        json_out($collection);
    }

    if ($method === 'GET' && $path === '/keys') {
        json_out(['keys' => search_keys((string) ($_GET['collection'] ?? ''), (string) ($_GET['q'] ?? ''))]);
    }

    if ($method === 'GET' && preg_match('~^/jobs/([a-f0-9]{64})$~', $path, $matches)) {
        $job = job_by_id($matches[1]);
        if (!$job) {
            fail('Not found', 404);
        }
        json_out(public_job($job));
    }

    if ($method === 'GET' && preg_match('~^/jobs/([a-f0-9]{64})/log$~', $path, $matches)) {
        if (!job_by_id($matches[1])) {
            fail('Not found', 404);
        }
        $log = job_log_path($matches[1]);
        if (!is_file($log)) {
            fail('No log yet', 404);
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        readfile($log);
        exit;
    }

    // Copy a file that is already on the server, under paths.imports.
    if ($method === 'POST' && $path === '/jobs') {
        check_csrf();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            fail('Invalid JSON');
        }
        $file = safe_import((string) ($data['source'] ?? ''));
        $title = trim((string) ($data['title'] ?? basename($file))) ?: basename($file);
        $collection = trim((string) ($data['collection'] ?? ''));
        $key = trim((string) ($data['logical_key'] ?? ''));
        require_placement_pair($collection, $key);
        $job = create_job($file, basename($file), $title, (bool) ($data['captions'] ?? true));
        json_out(placed_job($job, $collection, $key), 201);
    }

    if ($method === 'POST' && $path === '/uploads') {
        check_csrf();
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            fail('Upload failed. Check PHP upload_max_filesize and post_max_size.');
        }
        if ($_FILES['file']['size'] > cfg()['uploads']['max_bytes']) {
            fail('File exceeds configured size limit', 413);
        }
        $file = $_FILES['file'];
        if (!is_uploaded_file($file['tmp_name'])) {
            fail('Upload not valid');
        }
        $name = basename((string) $file['name']);
        $title = trim((string) ($_POST['title'] ?? '')) ?: $name;
        $collection = trim((string) ($_POST['collection'] ?? ''));
        $key = trim((string) ($_POST['logical_key'] ?? ''));
        require_placement_pair($collection, $key);
        $job = create_job($file['tmp_name'], $name, $title, isset($_POST['captions']));
        json_out(placed_job($job, $collection, $key), 201);
    }

    // Drop the files and the queue row. A running worker still owns a processing job.
    if ($method === 'DELETE' && preg_match('~^/jobs/([a-f0-9]{64})$~', $path, $matches)) {
        check_csrf();
        $job = job_by_id($matches[1]);
        if (!$job) {
            fail('Not found', 404);
        }
        if ($job['status'] === 'processing') {
            fail('Cannot delete a job while it is processing', 409);
        }
        delete_job($job);
        json_out(['deleted' => $matches[1]]);
    }

    // Retry reruns the whole job. Jobs left in "processing" are not retried here.
    if ($method === 'POST' && preg_match('~^/jobs/([a-f0-9]{64})/retry$~', $path, $matches)) {
        check_csrf();
        $job = job_by_id($matches[1]);
        if (!$job) {
            fail('Not found', 404);
        }
        if ($job['status'] !== 'failed') {
            fail('Only failed jobs can be retried', 409);
        }
        $statement = db()->prepare("UPDATE jobs SET status='queued', stage='queued', progress=0, error=NULL, started_at=NULL, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $statement->execute([$matches[1]]);
        start_worker();
        json_out(public_job(job_by_id($matches[1])));
    }

    // Start one worker and return. A worker that is already running exits on the lock.
    if ($method === 'POST' && $path === '/worker/poke') {
        check_csrf();
        $queued = (int) db()->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn();
        start_worker();
        json_out(['poked' => true, 'queued' => $queued]);
    }

    fail('Unknown endpoint', 404);
} catch (ConflictException $error) {
    fail($error->getMessage(), 409);
} catch (InvalidArgumentException $error) {
    fail($error->getMessage(), 400);
} catch (Throwable $error) {
    error_log($error);
    fail($error->getMessage(), 500);
}
