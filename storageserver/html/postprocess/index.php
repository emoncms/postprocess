<?php

error_reporting(E_ALL);
ini_set('display_errors', 'on');

if (!isset($_GET['q'])) die;
if (!isset($_GET['process'])) die;

$redis = new Redis();
$connected = $redis->connect("127.0.0.1");

$q = $_GET['q'];
$processitem = $_GET['process'];

$logger = new EmonLogger();

header('Content-Type: application/json');
switch ($q)
{
    case "addtoqueue":
        print "testing $processitem";
        //$redis->lrem("postprocessqueue",0,"$process:$input:$output");
        $redis->lpush("postprocessqueue",$processitem);
        break;
}
    
function get($index)
{
    $val = null;
    if (isset($_GET[$index])) $val = $_GET[$index];
    
    if (get_magic_quotes_gpc()) $val = stripslashes($val);
    return $val;
}

class EmonLogger
{
    public function __construct(){ }

    public function info ($message){
        print $message;
    }
    
    public function warn ($message){
        print $message;
    }
}
