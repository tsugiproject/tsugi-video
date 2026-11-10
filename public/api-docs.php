<?php
// API examples. Playback pages are public; these calls are not.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$base = rtrim(cfg()['base_url'], '/');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Server · API</title>
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/style.css') ?>">
    <style>
        .example{background:#101820;border-radius:10px;overflow:hidden;margin:10px 0 8px}
        .tabs{display:flex;gap:2px;padding:8px 8px 0;background:#1b2836}
        .tabs button{background:transparent;color:#c5d0dc;border-radius:6px 6px 0 0;padding:6px 12px;font-size:.85rem}
        .tabs button[aria-selected=true]{background:#101820;color:#fff}
        .example pre{margin:0;padding:14px 16px;color:#e7eef6;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;overflow:auto}
        .example code{font:inherit;white-space:pre}
        nav a{margin-right:12px}
    </style>
</head>
<body>
<main>
    <header>
        <h1>Video Server API</h1>
        <a href="<?= e(url_path('/')) ?>">Dashboard</a>
    </header>

    <section>
        <p>Every <code>/api/</code> call needs the admin user and password, or a Bearer token from <code>config.php</code>. These examples use the token. curl and Python do not send an Origin header, which is what the write check expects. A browser on another site cannot call the write endpoints with the admin password.</p>
        <p>The API only queues work. Run <code>php bin/worker.php</code> to process one queued video. The same file uploaded again is the same job. A new file uploaded with an existing collection and logical key becomes the video at that key.</p>
        <nav>
            <a href="#setup">Setup</a>
            <a href="#list">List</a>
            <a href="#one">One job</a>
            <a href="#upload">Upload</a>
            <a href="#import">Server path</a>
            <a href="#suggest">Names</a>
            <a href="#catalog">Collections</a>
            <a href="#log">Log</a>
            <a href="#retry">Retry</a>
            <a href="#delete">Delete</a>
            <a href="#play">Watch</a>
        </nav>
    </section>

    <section id="setup">
        <h2>Setup</h2>
        <p>Set the base URL and token once. Later examples assume these names exist. <code>call</code> returns JSON, or the raw text of the worker log.</p>
        <?php example(
            <<<SH
            BASE="$base"
            TOKEN="YOUR_TOKEN"
            SH,
            <<<'PY'
            import json
            import urllib.request

            BASE = "BASE_URL"
            TOKEN = "YOUR_TOKEN"

            def call(method, path, body=None, content_type=None):
                headers = {"Authorization": f"Bearer {TOKEN}"}
                if content_type:
                    headers["Content-Type"] = content_type
                request = urllib.request.Request(
                    BASE + path, data=body, headers=headers, method=method
                )
                with urllib.request.urlopen(request) as response:
                    payload = response.read().decode()
                    if "json" in response.headers.get("Content-Type", ""):
                        return json.loads(payload)
                    return payload
            PY
        ); ?>
    </section>

    <section id="list">
        <h2>List jobs</h2>
        <p><code>GET /api/jobs</code> returns the newest 200 jobs. <code>placements</code> is the collection and logical key currently pointing at that file.</p>
        <?php example(
            <<<'SH'
            curl -sS -H "Authorization: Bearer $TOKEN" \
              "$BASE/api/jobs"
            SH,
            <<<'PY'
            jobs = call("GET", "/api/jobs")
            for job in jobs["jobs"]:
                print(job["id"], job["status"], job["title"])
            PY
        ); ?>
    </section>

    <section id="one">
        <h2>Read one job</h2>
        <p><code>GET /api/jobs/{id}</code>. The id is the SHA-256 of the original file. <code>source_path</code> is not included.</p>
        <?php example(
            <<<'SH'
            curl -sS -H "Authorization: Bearer $TOKEN" \
              "$BASE/api/jobs/$ID"
            SH,
            <<<'PY'
            job = call("GET", "/api/jobs/" + job_id)
            print(job["status"], job["stage"], job["progress"], job["log_line"])
            PY
        ); ?>
    </section>

    <section id="upload">
        <h2>Upload a file</h2>
        <p><code>POST /api/uploads</code> as multipart form data. <code>collection</code> and <code>logical_key</code> are optional, and they have to be sent together. Letters, numbers, dots, underscores, and hyphens only, as in <code>PY4E</code> and <code>01_02_Intro</code>. Omit <code>captions</code> to skip Whisper. A finished copy of the same bytes comes back with <code>status</code> <code>completed</code> instead of being queued again.</p>
        <?php example(
            <<<'SH'
            curl -sS -H "Authorization: Bearer $TOKEN" \
              -F "file=@00-01-Intro.m4v" \
              -F "title=Intro" \
              -F "collection=PY4E" \
              -F "logical_key=01_02_Intro" \
              -F "captions=1" \
              "$BASE/api/uploads"
            SH,
            <<<'PY'
            import mimetypes
            import uuid

            path = "00-01-Intro.m4v"
            filename = path.rsplit("/", 1)[-1]
            mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"
            boundary = uuid.uuid4().hex

            def field(name, value):
                return (
                    f"--{boundary}\r\n"
                    f'Content-Disposition: form-data; name="{name}"\r\n\r\n'
                    f"{value}\r\n"
                ).encode()

            body = b"".join([
                field("title", "Intro"),
                field("collection", "PY4E"),
                field("logical_key", "01_02_Intro"),
                field("captions", "1"),
                (
                    f"--{boundary}\r\n"
                    f'Content-Disposition: form-data; name="file"; filename="{filename}"\r\n'
                    f"Content-Type: {mime}\r\n\r\n"
                ).encode(),
                open(path, "rb").read(),
                f"\r\n--{boundary}--\r\n".encode(),
            ])
            job = call("POST", "/api/uploads", body, f"multipart/form-data; boundary={boundary}")
            print(job["id"], job["placements"])
            PY
        ); ?>
    </section>

    <section id="import">
        <h2>Enqueue a file already on the server</h2>
        <p><code>POST /api/jobs</code> copies a path that is already under <code>paths.imports</code>. The request body is JSON.</p>
        <?php example(
            <<<'SH'
            curl -sS -H "Authorization: Bearer $TOKEN" \
              -H "Content-Type: application/json" \
              -d '{"source":"/efs/video-imports/lecture.mp4","title":"Lecture 1","collection":"DJ4E","logical_key":"02_03_Lesson","captions":true}' \
              "$BASE/api/jobs"
            SH,
            <<<'PY'
            body = json.dumps({
                "source": "/efs/video-imports/lecture.mp4",
                "title": "Lecture 1",
                "collection": "DJ4E",
                "logical_key": "02_03_Lesson",
                "captions": True,
            }).encode()
            job = call("POST", "/api/jobs", body, "application/json")
            PY
        ); ?>
    </section>

    <section id="suggest">
        <h2>Look up collection and key names</h2>
        <p><code>GET /api/collections?q=py</code> and <code>GET /api/keys?collection=PY4E&amp;q=01</code> return at most eight prefix matches. These are the lists the upload form uses.</p>
        <?php example(
            <<<'SH'
            curl -sS -H "Authorization: Bearer $TOKEN" \
              --get "$BASE/api/collections" --data-urlencode "q=py"
            curl -sS -H "Authorization: Bearer $TOKEN" \
              --get "$BASE/api/keys" \
              --data-urlencode "collection=PY4E" --data-urlencode "q=01"
            SH,
            <<<'PY'
            from urllib.parse import urlencode

            collections = call("GET", "/api/collections?" + urlencode({"q": "py"}))
            keys = call("GET", "/api/keys?" + urlencode({"collection": "PY4E", "q": "01"}))
            print(collections["collections"], keys["keys"])
            PY
        ); ?>
    </section>

    <section id="catalog">
        <h2>List collections</h2>
        <p><code>GET /api/collections</code> with no <code>q</code> returns every collection name. <code>GET /api/collections/{collection}</code> returns every logical key in that collection and the video it points at. An unknown collection is 404. The name match ignores capitalization.</p>
        <?php example(
            <<<'SH'
            curl -sS -H "Authorization: Bearer $TOKEN" \
              "$BASE/api/collections"
            curl -sS -H "Authorization: Bearer $TOKEN" \
              "$BASE/api/collections/PY4E"
            SH,
            <<<'PY'
            from urllib.parse import quote

            names = call("GET", "/api/collections")
            print(names["collections"])
            course = call("GET", "/api/collections/" + quote("PY4E"))
            for item in course["items"]:
                print(item["logical_key"], item["status"], item["title"])
            PY
        ); ?>
    </section>

    <section id="log">
        <h2>Read the worker log</h2>
        <p><code>GET /api/jobs/{id}/log</code> is plain text, the same file as <code>var/logs/{id}.log</code>. The response is 404 until the worker has written a line.</p>
        <?php example(
            <<<'SH'
            curl -sS -H "Authorization: Bearer $TOKEN" \
              "$BASE/api/jobs/$ID/log"
            SH,
            <<<'PY'
            print(call("GET", "/api/jobs/" + job_id + "/log"))
            PY
        ); ?>
    </section>

    <section id="retry">
        <h2>Retry a failed job</h2>
        <p><code>POST /api/jobs/{id}/retry</code>. Only a job whose status is <code>failed</code>. Send a JSON content type. A job left in <code>processing</code> is not retried here.</p>
        <?php example(
            <<<'SH'
            curl -sS -X POST -H "Authorization: Bearer $TOKEN" \
              -H "Content-Type: application/json" \
              -d '{}' \
              "$BASE/api/jobs/$ID/retry"
            SH,
            <<<'PY'
            job = call("POST", "/api/jobs/" + job_id + "/retry", b"{}", "application/json")
            PY
        ); ?>
    </section>

    <section id="delete">
        <h2>Delete a job</h2>
        <p><code>DELETE /api/jobs/{id}</code> removes the original, the rendered files, the queue row, the log, and any collection key that points at it. Send a JSON content type. Deleting a job that is <code>processing</code> returns 409.</p>
        <?php example(
            <<<'SH'
            curl -sS -X DELETE -H "Authorization: Bearer $TOKEN" \
              -H "Content-Type: application/json" \
              "$BASE/api/jobs/$ID"
            SH,
            <<<'PY'
            print(call("DELETE", "/api/jobs/" + job_id, b"{}", "application/json"))
            PY
        ); ?>
    </section>

    <section id="play">
        <h2>Watch and embed</h2>
        <p>Playback URLs are public. <code>watch_url</code> and <code>embed_url</code> on the job always address that exact file. Each entry in <code>placements</code> addresses the collection and key, and follows whatever video you upload to that key next.</p>
        <?php example(
            <<<SH
            curl -sS -H "Authorization: Bearer \$TOKEN" "\$BASE/api/jobs/\$ID"
            # watch_url is the file. placements[].watch_url is the collection key.
            # $base/watch/PY4E/01_02_Intro
            # $base/embed/PY4E/01_02_Intro
            SH,
            <<<'PY'
            job = call("GET", "/api/jobs/" + job_id)
            print(job["watch_url"])
            for place in job["placements"]:
                print(place["collection"], place["logical_key"], place["watch_url"])
            PY
        ); ?>
        <p class="muted">Writes return 400 for a bad name or path, 409 when a file is already processing or a delete or retry is not allowed, 413 when the upload is over the configured limit, and 401 when the token or password is wrong.</p>
    </section>
</main>
<script>
const stored = localStorage.getItem('api-doc-lang') || 'curl';
function show(lang) {
    localStorage.setItem('api-doc-lang', lang);
    document.querySelectorAll('.example').forEach(example => {
        example.querySelectorAll('[data-lang]').forEach(button => {
            const on = button.dataset.lang === lang;
            button.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        example.querySelectorAll('[data-pane]').forEach(pane => {
            pane.hidden = pane.dataset.pane !== lang;
        });
    });
}
document.querySelectorAll('.example [data-lang]').forEach(button => {
    button.addEventListener('click', () => show(button.dataset.lang));
});
show(stored);
</script>
</body>
</html>
<?php
function example(string $curl, string $python): void
{
    global $base;
    $python = str_replace('BASE_URL', $base, $python);
    echo '<div class="example">';
    echo '<div class="tabs" role="tablist">';
    echo '<button type="button" data-lang="curl" aria-selected="true">curl</button>';
    echo '<button type="button" data-lang="python" aria-selected="false">Python</button>';
    echo '</div>';
    echo '<pre data-pane="curl"><code>' . e(trim($curl)) . '</code></pre>';
    echo '<pre data-pane="python" hidden><code>' . e(trim($python)) . '</code></pre>';
    echo '</div>';
}
