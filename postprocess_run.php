<?php
// Lock file
$fp = fopen("/tmp/postprocess-runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

// Get script location
list($scriptPath) = get_included_files();
$basedir = str_replace("/postprocess_run.php","",$scriptPath);

// Load emoncms 
require "/var/www/emoncms/Lib/load_emoncms.php";

include "Modules/postprocess/postprocess_model.php";
$postprocess = new PostProcess($mysqli, $redis, $feed);
$postprocess->datadir = $settings['feed']['phpfina']['datadir'];

chdir($basedir);
require "common.php";
require "request.php";

$postprocess->get_processes("$linked_modules_dir/postprocess");
$process_classes = $postprocess->get_process_classes();

while (true) {
    if ($process = $postprocess->pop_process_queue()) {
        print json_encode($process)."\n";

        $result = $process_classes[$process->params->process]->process($process->params);
        
        if ($result['success'] || !isset($result['success'])) {
            $postprocess->update_status($process->userid,$process->processid,"finished");
            if (isset($result['message'])) {
                print "Success ".$result['message']."\n";
            } else {
                print "Success\n";
            }
        } else {
            $postprocess->update_status($process->userid,$process->processid,"error");
            if (isset($result['message'])) {
                print "Error ".$result['message']."\n";
            } else {
                print "Error\n";
            }
        }
    } else {
        break;
    }
    sleep(1);
}