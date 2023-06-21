<?php
// Lock file
$fp = fopen("/tmp/postprocess-runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

// Get script location
list($scriptPath) = get_included_files();
$basedir = str_replace("/postprocess_run.php","",$scriptPath);

require "common.php";
require "request.php";

// Load emoncms 
require "/var/www/emoncms/Lib/load_emoncms.php";

include "Modules/postprocess/postprocess_model.php";
$postprocess = new PostProcess($mysqli, $redis, $feed);
$postprocess->datadir = $settings['feed']['phpfina']['datadir'];

$postprocess->get_processes("$linked_modules_dir/postprocess");
$process_classes = $postprocess->get_process_classes();

while (true) {
    if ($process = $postprocess->pop_process_queue()) {
        print json_encode($process)."\n";

        $result = $process_classes[$process->params->process]->process($process->params);
        
        if ($result['success']) {
            print "Success: ".$result['message']."\n";
            $postprocess->update_status($process->userid,$process->processid,"finished",$result['message']);
            $feed->update_user_feeds_size($process->userid);
        } else {
            $postprocess->update_status($process->userid,$process->processid,"error",$result['message']);
            print "Error: ".$result['message']."\n";
        }
    } else {
        break;
    }
    sleep(1);
}