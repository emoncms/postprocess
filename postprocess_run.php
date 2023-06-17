<?php
// Get script location
list($scriptPath) = get_included_files();
$basedir = str_replace("/postprocess_run.php","",$scriptPath);

define('EMONCMS_EXEC', 1);
chdir("/var/www/emoncms");
require "process_settings.php";
$dir = $settings["feed"]["phpfina"]["datadir"];
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
    
    // full file location and name
    $process_file = $basedir."/processes/".$process.".php";
    
    // Include the process file and check that process function exists
    require $process_file;
    if (class_exists("PostProcess_".$process)) {
        $process_class = "PostProcess_".$process;
        $processes[$process] = new $process_class($dir);
    } else {
        echo "Error: missing process class: $process\n"; die;
    }
}

if (!$settings['redis']['enabled']) { echo "ERROR: Redis is not enabled"; die; }
$redis = new Redis();
$connected = $redis->connect($settings['redis']['host'], $settings['redis']['port']);
if (!$connected) { echo "Can't connect to redis at ".$settings['redis']['host'].":".$settings['redis']['port']; die; }
if (!empty($settings['redis']['prefix'])) $redis->setOption(Redis::OPT_PREFIX, $settings['redis']['prefix']);
if (!empty($settings['redis']['auth'])) {
    if (!$redis->auth($settings['redis']['auth'])) {
        echo "Can't connect to redis at ".$settings['redis']['host'].", autentication failed"; die;
    }
}

while(true){
    $len = $redis->llen("postprocessqueue");

    if ($len>0) {
        $processitem = $redis->lpop("postprocessqueue");
        print $processitem."\n";

        $processitem = json_decode($processitem);
        if ($processitem!=null) {
            $process = $processitem->process;
            if (isset($processes[$process])) {
                $result = $processes[$process]->process($processitem);
                if (isset($result['success'])) {
                    if ($result['success']) {
                        if (isset($result['message'])) {
                            print "Success: ".$result['message']."\n";
                        } else {
                            print "Success\n";
                        }
                    } else {
                        print "Error: ".$result['message']."\n";
                    }
                }
            }
        }

    } else {
        break;
    }
    sleep(1);
}
