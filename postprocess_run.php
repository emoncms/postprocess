<?php

define('EMONCMS_EXEC', 1);
chdir("/var/www/emoncms");
require "process_settings.php";
if (!isset($homedir)) $homedir = "/home/pi";
$basedir = "$homedir/postprocess/";
$dir = $feed_settings["phpfina"]["datadir"];
chdir($basedir);

$fp = fopen("/tmp/postprocess-runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

require "common.php";
require "request.php";
require "powertokwh.php";
require "exportcalc.php";
require "importcalc.php";
require "addfeeds.php";
require "scalefeed.php";
require "trimfeedstart.php";
require "mergefeeds.php";

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
    
    global $dir;
    if ($processitem->process=="powertokwh") $result = powertokwh($dir,$processitem);
    // if ($processitem->process=="trimfeedstart") $result = trimfeedstart($dir,$processitem);
    // if ($processitem->process=="exportcalc") $result = exportcalc($dir,$processitem);
    if ($processitem->process=="importcalc") $result = importcalc($dir,$processitem);
    if ($processitem->process=="addfeeds") $result = addfeeds($dir,$processitem);
    if ($processitem->process=="scalefeed") $result = scalefeed($dir,$processitem);
    if ($processitem->process=="mergefeeds") $result = mergefeeds($dir,$processitem);
}

function updatetimevalue($id,$time,$value){
    global $redis;
    $redis->hMset("feed:$id", array('value' => $value, 'time' => $time));
}
