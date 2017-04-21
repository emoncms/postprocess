<?php

require "postprocess.settings.php";

$fp = fopen($basedir."runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

define('EMONCMS_EXEC', 1);

require "common.php";
require "request.php";
require "powertokwh.php";
require "exportcalc.php";
require "importcalc.php";
require "addfeeds.php";
require "scalefeed.php";
require "trimfeedstart.php";

$redis = new Redis();
$connected = $redis->connect("127.0.0.1");

while(true){
    $len = $redis->llen("postprocessqueue");

    if ($len>0) {
        $processitem = $redis->lpop("postprocessqueue");
        print $processitem."\n";
        process($processitem);
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
}

function updatetimevalue($id,$time,$value){
    global $host, $auth;
    http_request("POST",$host."postprocess/updatetimevalue","auth=$auth&feedid=$id&time=$time&value=$value");
}
