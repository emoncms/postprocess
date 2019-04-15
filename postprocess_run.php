<?php
// Get script location
list($scriptPath) = get_included_files();
$basedir = str_replace("/postprocess_run.php","",$scriptPath);

define('EMONCMS_EXEC', 1);
chdir("/var/www/emoncms");
require "process_settings.php";
$dir = $feed_settings["phpfina"]["datadir"];
chdir($basedir);

$fp = fopen("/tmp/postprocess-runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

require "common.php";
require "request.php";

// Auto load processes
$processes = array();
$files = scandir($basedir."/processes");
for ($i=2; $i<count($files); $i++) {

    // compile process list
    $process = str_replace(".php","",$files[$i]);
    $processes[] = $process;
    
    // full file location and name
    $process_file = $basedir."/processes/".$process.".php";
    
    // Include the process file and check that process function exists
    require $process_file;
    if (!function_exists($process)) {
        echo "Error: missing process function: $process\n"; die;
    }
}

if (!$redis_enabled) { echo "ERROR: Redis is not enabled"; die; }
$redis = new Redis();
$connected = $redis->connect($redis_server['host'], $redis_server['port']);
if (!$connected) { echo "Can't connect to redis at ".$redis_server['host'].":".$redis_server['port']; die; }
if (!empty($redis_server['prefix'])) $redis->setOption(Redis::OPT_PREFIX, $redis_server['prefix']);
if (!empty($redis_server['auth'])) {
    if (!$redis->auth($redis_server['auth'])) {
        echo "Can't connect to redis at ".$redis_server['host'].", autentication failed"; die;
    }
}

while(true){
    $len = $redis->llen("postprocessqueue");

    if ($len>0) {
        $processitem = $redis->lpop("postprocessqueue");
        print $processitem."\n";
        process($processitem);
    } else {
        break;
    }
    sleep(1);
}

function process($processitem) {

    $processitem = json_decode($processitem);
    if ($processitem==null) return false;
    
    global $dir,$processes;
    
    $process = $processitem->process;
    if (array_search($process,$processes)!==false) {
              
        $result = $process($dir,$processitem);

    }
}

function updatetimevalue($id,$time,$value){
    global $redis;
    $redis->hMset("feed:$id", array('value' => $value, 'time' => $time));
}
