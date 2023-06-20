<?php
// Run process manually
// This script is intended to be run from the command line

require "common.php";
require "processes/powertokwh.php";

// Load emoncms
require "/var/www/emoncms/Lib/load_emoncms.php";
$userid = 27;

// Create an output feed if it does not exist
if (!$output_feedid = $feed->get_id($userid,"solar_kwh")) {
    $result = $feed->create($userid,"postprocess","solar_kwh",5,(object)array("interval"=>10),'kWh');
    if (!$result["success"]) die("Error: could not create feed");
    $output_feedid = $result["feedid"];
}
// Clear the output feed
$feed->clear($output_feedid);

$process = new PostProcess_powertokwh($settings["feed"]["phpfina"]["datadir"]);
$result = $process->process((object)array(
    "input"=>$feed->get_id($userid,"solar"),
    "output"=>$output_feedid,
    "max_power"=>10000,
    "min_power"=>-10000,
    "process_mode"=>"all"
));
if (!$result["success"]) {
    print "Error: ".$result["message"]."\n";
}
