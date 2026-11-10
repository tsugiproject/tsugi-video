<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
set_time_limit(0);
// flock works on Ubuntu and macOS (unlike invoking the Linux-only `flock` utility).
$lock=fopen(pathof('lock_file'),'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Worker already running\n";exit(0); }
init_db();
require_media_mount();
// Atomically claim one queued job in a transaction.
$db=db();$db->exec('BEGIN IMMEDIATE');
try {
 $j=$db->query("SELECT * FROM jobs WHERE status='queued' ORDER BY created_at,id LIMIT 1")->fetch();
 if (!$j) { $db->commit();echo "Queue empty\n";exit(0); }
 $stmt=$db->prepare("UPDATE jobs SET status='processing',stage='probe',progress=1,started_at=CURRENT_TIMESTAMP,finished_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?");
 $stmt->execute([$j['id']]);$db->commit();
} catch(Throwable $e) { $db->rollBack(); throw $e; }
$id=$j['id'];$root=rtrim(pathof('storage'),'/');$work=$root.'/work/'.$id;$final=$root.'/media/'.$id;
if (!is_dir($work)) mkdir($work,0770,true);
$log=$work.'/worker.log';
function logmsg(string $msg):void {global $log,$id;file_put_contents($log,gmdate('c').' '.$msg."\n",FILE_APPEND);echo "$id: $msg\n";}
function command_run(array $args, ?callable $progress=null):void {
 global $log;
 $spec=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$log,'a']];
 $p=proc_open($args,$spec,$pipes,null,null,['bypass_shell'=>true]);
 if (!is_resource($p)) throw new RuntimeException('Could not launch '.($args[0]??'command'));
 stream_set_blocking($pipes[1],true);
 try {
  while (($line=fgets($pipes[1])) !== false) {if ($progress) $progress(trim($line));}
 } finally { fclose($pipes[1]); }
 $exit=proc_close($p);
 if ($exit !== 0) throw new RuntimeException(($args[0]??'Command').' failed with exit '.$exit.'; see worker log');
}
function probe(string $source):array {
 $cmd=[pathof('ffprobe'),'-v','error','-show_entries','format=duration:stream=codec_type,codec_name,width,height','-of','json',$source];
 $spec=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']];
 $p=proc_open($cmd,$spec,$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($p))throw new RuntimeException('ffprobe could not start');
 $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
 if(proc_close($p)!==0)throw new RuntimeException('Invalid media file: '.substr($err,0,400));
 $data=json_decode($out,true);if(!is_array($data))throw new RuntimeException('Invalid ffprobe JSON');return $data;
}
function progress_ffmpeg(string $line):void {
 global $id,$duration;
 if (preg_match('/^out_time_us=(\d+)$/',$line,$m) && $duration>0) {
    update_job($id,'processing','hls',5+70*min(1,((float)$m[1]/1e6)/$duration));
 }
}
try {
 logmsg('Starting: '.$j['original_name']);
 $p=probe($j['source_path']);$duration=(float)($p['format']['duration']??0);
 if($duration<=0)throw new RuntimeException('Source has no readable duration');
 $v=null;$a=null;
 foreach(($p['streams']??[]) as $stream){if(($stream['codec_type']??'')==='video' && !$v)$v=$stream;if(($stream['codec_type']??'')==='audio' && !$a)$a=$stream;}
 if(!$v)throw new RuntimeException('Video stream required');
 $stmt=db()->prepare('UPDATE jobs SET duration=? WHERE id=?');$stmt->execute([$duration,$id]);
 $video=cfg()['video'];$height=(int)$video['height'];$copy=($video['copy_compatible']??false) && ($v['codec_name']??'')==='h264' && ($a['codec_name']??'')==='aac' && (int)($v['height']??9999)<=$height;
 $args=[pathof('ffmpeg'),'-hide_banner','-loglevel','warning','-nostdin','-y','-progress','pipe:1','-stats_period','2','-i',$j['source_path'],'-map','0:v:0','-map','0:a:0?'];
 if ($copy) { $args=array_merge($args,['-c','copy']);logmsg('HLS stream copy enabled'); }
 else {
    $scale='scale=-2:trunc(min(ih\\,'.$height.')/2)*2';
    $args=array_merge($args,['-vf',$scale,'-c:v','libx264','-preset',(string)$video['preset'],'-crf',(string)$video['crf'],'-threads',(string)$video['threads'],'-pix_fmt','yuv420p','-force_key_frames','expr:gte(t,n_forced*'.(int)$video['segment_seconds'].')','-c:a','aac','-b:a','128k']);
    logmsg('HLS transcoding enabled');
 }
 update_job($id,'processing','hls',5);
 $args=array_merge($args,['-f','hls','-hls_time',(string)$video['segment_seconds'],'-hls_playlist_type','vod','-hls_segment_filename',$work.'/segment%05d.ts',$work.'/index.m3u8']);
 command_run($args,'progress_ffmpeg');
 if (!is_file($work.'/index.m3u8')) throw new RuntimeException('No HLS playlist generated');
 if ((int)$j['captions'] === 1 && (bool)cfg()['captions']['enabled']) {
   update_job($id,'processing','audio',77);logmsg('Extracting Whisper input');
   $wav=$work.'/audio.wav';
   command_run([pathof('ffmpeg'),'-hide_banner','-loglevel','error','-nostdin','-y','-i',$j['source_path'],'-vn','-ar','16000','-ac','1','-c:a','pcm_s16le',$wav]);
   update_job($id,'processing','whisper',83);logmsg('Generating captions');
   $cap=cfg()['captions'];
   command_run([pathof('whisper'),'-m',pathof('whisper_model'),'-f',$wav,'-l',(string)$cap['language'],'-t',(string)$cap['threads'],'-ovtt','-of',$work.'/captions']);
   @unlink($wav);
   if (!is_file($work.'/captions.vtt')) throw new RuntimeException('Whisper did not produce captions.vtt');
 }
 require_media_mount();
 update_job($id,'processing','publish',98);
 // Only the final /media path is exposed by Apache; incomplete artifacts stay in /work.
 if (file_exists($final)) throw new RuntimeException('Published directory already exists; intervention required');
 if (!rename($work,$final)) throw new RuntimeException('Atomic publish rename failed (ensure work/media share a filesystem)');
 $stmt=db()->prepare("UPDATE jobs SET status='completed',stage='completed',progress=100,finished_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?");$stmt->execute([$id]);
 echo "Completed $id\n";
} catch(Throwable $e) {
 logmsg('ERROR: '.$e->getMessage());
 update_job($id,'failed','failed',(float)(job_by_id($id)['progress']??0),$e->getMessage());
 exit(1);
}
