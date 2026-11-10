<?php
require dirname(__DIR__).'/src/bootstrap.php';
admin_auth();
$jobs=db()->query('SELECT * FROM jobs ORDER BY created_at DESC LIMIT 200')->fetchAll();
$counts=['queued'=>0,'processing'=>0,'completed'=>0,'failed'=>0];
foreach ($counts as $k=>$_) { $s=db()->prepare('SELECT COUNT(*) FROM jobs WHERE status=?');$s->execute([$k]);$counts[$k]=(int)$s->fetchColumn(); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Video Server · Dashboard</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<main><header><h1>Video Server</h1><span class="muted">video.tsugi.org · Standalone video processing</span></header>
<section class="stats"><?php foreach ($counts as $name=>$count): ?><div class="stat"><small><?=e(ucfirst($name))?></small><strong id="count-<?=e($name)?>"><?= $count ?></strong></div><?php endforeach; ?></section>
<section><h2>Upload a video</h2><form id="upload" enctype="multipart/form-data"><label>Video file <input type="file" name="file" accept="video/*" required></label><label>Title <input name="title" placeholder="Optional title"></label><label><input type="checkbox" name="captions" value="1" checked> Generate Whisper captions</label><button type="submit">Upload and enqueue</button><output id="upload-status"></output></form></section>
<section><h2>Jobs <small class="muted">refreshes every 5 seconds</small></h2><table><thead><tr><th>Title</th><th>Stage</th><th>Progress</th><th>Actions</th></tr></thead><tbody id="jobs"><?php foreach ($jobs as $j): ?><tr><td><?=e($j['title'])?><small><?=e($j['original_name'])?></small></td><td><?=e($j['status'].' / '.$j['stage'])?></td><td><?=round((float)$j['progress'])?>%</td><td><?php if ($j['status']==='completed'): ?><a href="/watch/<?=e($j['id'])?>">Watch</a> · <a href="/embed/<?=e($j['id'])?>">Embed</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
</main><script src="/assets/dashboard.js"></script></body></html>
