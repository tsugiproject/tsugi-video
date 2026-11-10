<?php
require_once dirname(__DIR__).'/src/bootstrap.php';
admin_auth();
$method=$_SERVER['REQUEST_METHOD'];
$path=preg_replace('~^/api~','',request_path());
try {
 if ($method==='GET' && $path==='/jobs') {
    $rows=db()->query('SELECT * FROM jobs ORDER BY created_at DESC LIMIT 200')->fetchAll();
    json_out(['jobs'=>array_map('public_job',$rows)]);
 }
 if ($method==='GET' && preg_match('~^/jobs/([a-f0-9]{24})$~',$path,$m)) {
    $j=job_by_id($m[1]); if (!$j) fail('Not found',404); json_out(public_job($j));
 }
 if ($method==='POST' && $path==='/jobs') {
    check_csrf();
    $data=json_decode(file_get_contents('php://input'),true);
    if (!is_array($data)) fail('Invalid JSON');
    $file=safe_import((string)($data['source'] ?? ''));
    $job=create_job($file,basename($file),trim((string)($data['title'] ?? basename($file))) ?: basename($file),(bool)($data['captions'] ?? true));
    json_out(public_job($job),201);
 }
 if ($method==='POST' && $path==='/uploads') {
    check_csrf();
    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) fail('Upload failed. Check PHP upload_max_filesize and post_max_size.');
    if ($_FILES['file']['size'] > cfg()['uploads']['max_bytes']) fail('File exceeds configured size limit',413);
    $file=$_FILES['file'];
    if (!is_uploaded_file($file['tmp_name'])) fail('Upload not valid');
    $name=basename((string)$file['name']);
    $title=trim((string)($_POST['title'] ?? '')) ?: $name;
    $job=create_job($file['tmp_name'],$name,$title,isset($_POST['captions']));
    json_out(public_job($job),201);
 }
 if ($method==='POST' && preg_match('~^/jobs/([a-f0-9]{24})/retry$~',$path,$m)) {
    check_csrf(); $j=job_by_id($m[1]);if (!$j) fail('Not found',404);
    if ($j['status']!=='failed') fail('Only failed jobs can be retried',409);
    $s=db()->prepare("UPDATE jobs SET status='queued',stage='queued',progress=0,error=NULL,started_at=NULL,finished_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?");$s->execute([$m[1]]);
    json_out(public_job(job_by_id($m[1])));
 }
 fail('Unknown endpoint',404);
} catch (InvalidArgumentException $e) { fail($e->getMessage(),400); }
catch (Throwable $e) { error_log($e); fail('Server error',500); }
