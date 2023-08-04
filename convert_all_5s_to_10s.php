<?php
// Run process manually
// This script is intended to be run from the command line

require "common.php";
require "processes/downsample.php";

// Load emoncms
require "/var/www/emoncms/Lib/load_emoncms.php";
$userid = 1;

$process = new PostProcess_downsample($settings["feed"]["phpfina"]["datadir"]);

$feeds = $feed->get_user_feeds($userid,true);

foreach ($feeds as $feed) {
    if ($feed["engine"]==Engine::PHPFINA && $feed["interval"]==5) {
        print $feed["id"]." ".$feed["name"]."\n";

        $result = $process->process((object)array(
            "feed"=>$feed["id"],
            "new_interval"=>10,
            "backup"=>false
        ));

        print json_encode($result)."\n";
    }
}