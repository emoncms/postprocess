<?php

class PostProcess_common
{
    public $dir;

    public function __construct($dir)
    {
        $this->dir = $dir;
    }

    public function description()
    {
        return array(
            "name" => "common",
            "description" => "Common functions for postprocessing",
            "settings" => array()
        );
    }

    public function process($processitem)
    {
        return false;
    }

    public function validate($processitem)
    {
        $settings = $this->description()["settings"];
        foreach ($settings as $key=>$setting) {
            if (!isset($processitem->{$key})) {
                return array("success" => false, "message" => "setting ".$key." not set\n");
            }

            if ($setting["type"] == "feed" || $setting["type"] == "newfeed") {
                $feedid = (int) $processitem->{$key};
                if (!file_exists($this->dir.$feedid.".meta")) {
                    if (isset($setting["optional"]) && $setting["optional"]) {
                        continue;
                    }
                    return array("success" => false, "message" => "setting: $key, feed: $feedid.meta does not exist\n");
                }
                // check if feed is readable
                if (!is_readable($this->dir.$feedid.".meta")) {
                    return array("success" => false, "message" => "setting: $key, feed: $feedid.meta is not readable\n");
                }
                // check if feed is writable
                if ($setting["type"] == "newfeed" && !is_writable($this->dir.$feedid.".meta")) {
                    return array("success" => false, "message" => "setting: $key, feed: $feedid.meta is not writable\n");
                }
            }

            if ($setting['type']=="value") {
                $value = (float) 1*$processitem->$key;
                if ($value!=$processitem->$key) {
                    return array("success"=>false,"message"=>"invalid value");
                }
            }

            if ($setting['type']=="interval") {
                // check if interval is valid
                if (is_numeric($processitem->key)) {
                    $processitem->key = (int) $processitem->key;
                }
                if (!in_array($processitem->key, array('original',10,30,60,1800,3600))) {
                    return array("success"=>false,"message"=>"invalid interval");
                }
            }

            if ($setting['type']=="timezone") {
                if (!$datetimezone = new DateTimeZone($processitem->{$key})) {
                    return array("success"=>false,"message"=>"invalid timezone");
                }
            }

            if ($setting['type']=="formula") {
                
            }
        }

        return array("success" => true);
    }
}

function updatetimevalue($id,$time,$value){
    global $redis;
    $redis->hMset("feed:$id", array('value' => $value, 'time' => $time));
}

function getmeta($dir, $id)
{
    if (!file_exists($dir . $id . ".meta")) {
        print "input file $id.meta does not exist\n";
        return false;
    }

    $meta = new stdClass();
    $metafile = fopen($dir . $id . ".meta", 'rb');
    fseek($metafile, 8);
    $tmp = unpack("I", fread($metafile, 4));
    $meta->interval = $tmp[1];
    $tmp = unpack("I", fread($metafile, 4));
    $meta->start_time = $tmp[1];
    fclose($metafile);

    clearstatcache($dir . $id . ".dat");
    $npoints = floor(filesize($dir . $id . ".dat") / 4.0);
    $meta->npoints = $npoints;

    $meta->end_time = $meta->start_time + ($meta->npoints * $meta->interval);

    return $meta;
}

function createmeta($dir, $id, $meta)
{
    $metafile = fopen($dir . $id . ".meta", 'wb');
    fwrite($metafile, pack("I", 0));
    fwrite($metafile, pack("I", 0));
    fwrite($metafile, pack("I", $meta->interval));
    fwrite($metafile, pack("I", $meta->start_time));
    fclose($metafile);
}

//compute meta datas of different feeds intended for a preprocessing
function compute_meta(): stdClass
{
    $numargs = func_num_args();
    $arg_list = func_get_args();
    $all_intervals = [];
    $all_start_times = [];
    $all_ending_times = [];
    $meta = new stdClass();
    for ($i = 0; $i < $numargs; $i++) {
        $all_intervals[] = $arg_list[$i]->interval;
        $all_start_times[] = $arg_list[$i]->start_time;
        $all_ending_times[] = $arg_list[$i]->start_time + $arg_list[$i]->npoints * $arg_list[$i]->interval;
    }
    $meta->interval = max($all_intervals);
    $meta->start_time = floor(max($all_start_times) / $meta->interval) * $meta->interval;
    $meta->writing_end_time = min($all_ending_times);
    //print("intervals.....");print_r($all_intervals);
    //print("start_times....");print_r($all_start_times);
    //print("ending_times.....");print_r($all_ending_times);
    print("NOTICE : output interval=$meta->interval, start=$meta->start_time, end=$meta->writing_end_time \n");
    return $meta;
}

/*
format an array for the formula engine
$b is the result of a preg_match on a formula chunk with the regexp /($Xop)?($Xnbr)?($Xf)?/
$Xop : regexp for operator
$Xnbr : regexp for float or int value
$Xf : regexp for a feed
cf process basic_formula for more details on $Xop,$Xnbr,$Xf
ouputs a formula element as a 3 elements vector :
0 -> type of data ie "feed" ou "value"
1 -> operator
2 -> value or feed number
*/
function ftoa($b): array
{
    $c = [];
    //print_r($b);
    if (sizeof(value: $b) == 4) {
        $c[0] = "feed";
        $c[2] = intval(value: substr(string: $b[3], offset: 1));
    } else {
        $c[0] = "value";
        $c[2] = $b[2];
    }
    $c[1] = $b[1];
    if (!$c[2]) $c[2] = 1;
    if (!$c[1]) $c[1] = '+';
    return $c;
}

/*
output of a basic formula for a specified time
the formula is described by the $elements array > a third dimensionnal array > datas are on level 2
for a given level 1, we operate multiplication/division within level 2 elements and give a sign to the result
the final result is the addition of the whole
a formula element is a 3 elements vector :
0 -> type of data ie "feed" ou "value"
1 -> operator
2 -> value or feed number
feeds_meta : array of metas such as produced by getmeta
*/
function bfo($elements, $feeds_meta, $feeds_dat, $time): float|int
{
    $s = [];
    foreach ($elements as $element) {
        $values = [];
        foreach ($element as $e) {
            $value = NAN;
            if ($e[0] == "feed") {
                $pos = floor(num: ($time - $feeds_meta[$e[2]]->start_time) / $feeds_meta[$e[2]]->interval);
                if ($pos >= 0 && $pos < $feeds_meta[$e[2]]->npoints) {
                    fseek(stream: $feeds_dat[$e[2]], offset: $pos * 4);
                    $tmp = unpack("f", fread($feeds_dat[$e[2]], 4));
                    $value = $tmp[1];
                }
            }
            if ($e[0] == "value") $value = $e[2];
            if (!is_nan(num: $value) && $value != 0) {
                if ($e[1] == "/") $value = 1 / $value;
                if ($e[1] == "-") $value = -$value;
            }
            $values[] = $value;
        }
        if (!in_array(needle: NAN, haystack: $values)) {
            $s[] = array_product(array: $values);
        } else $s[] = NAN;
    }
    if (!in_array(needle: NAN, haystack: $s)) {
        $sum = array_sum(array: $s);
    } else $sum = NAN;

    return $sum;
}

class ModelHelper
{
    private $dir;
    private $params;
    private $fh = array();
    private $buffer = array();
    public $meta = array();
    public $value = array();

    public $start_time = false;
    public $end_time = false;

    public function __construct($dir, $params)
    {
        $this->dir = $dir;
        $this->params = $params;
    }

    private function load($key, $mode)
    {
        // check for valid key
        if (!isset($this->params->$key)) return false;
        $feedid = $this->params->$key;
        // load meta
        if (!$meta = getmeta($this->dir, $feedid)) return false;
        $this->meta[$key] = $meta;

        if (!$this->fh[$key] = @fopen($this->dir . $feedid . ".dat", $mode)) {
            echo "ERROR: could not open " . $this->dir . $feedid . ".dat\n";
            return false;
        }

        // Fetch last value
        $this->value[$key] = 0.0;
        if ($meta->npoints > 0) {
            fseek($this->fh[$key], ($meta->npoints - 1) * 4);
            $tmp = unpack("f", fread($this->fh[$key], 4));
            if (!is_nan($tmp[1])) $this->value[$key] = 1 * $tmp[1];
        }
        return true;
    }

    public function input($key)
    {
        $result = $this->load($key, 'rb');
        if ($this->start_time === false) $this->start_time = $this->meta[$key]->start_time;
        else if ($this->meta[$key]->start_time > $this->start_time) $this->start_time = $this->meta[$key]->start_time;
        if ($this->end_time === false) $this->end_time = $this->meta[$key]->end_time;
        else if ($this->meta[$key]->end_time < $this->end_time) $this->end_time = $this->meta[$key]->end_time;
        return $result;
    }

    public function output($key)
    {
        if (!$this->load($key, 'c+')) return false;
        $this->buffer[$key] = "";
        return true;
    }

    public function read($key, $value)
    {
        $tmp = unpack("f", fread($this->fh[$key], 4));
        if ($tmp[1] != null && !is_nan($tmp[1])) $value = $tmp[1];
        return $value;
    }

    public function seek_to_time($time)
    {
        foreach (array_keys($this->fh) as $key) {
            $pos = floor(($time - $this->meta[$key]->start_time) / $this->meta[$key]->interval);
            fseek($this->fh[$key], $pos * 4);
        }
    }

    public function seek_to_time_inputs($time)
    {
        foreach (array_keys($this->fh) as $key) {
            if (!isset($this->buffer[$key])) {
                $pos = floor(($time - $this->meta[$key]->start_time) / $this->meta[$key]->interval);
                fseek($this->fh[$key], $pos * 4);
            }
        }
    }

    public function write($key, $value)
    {
        $this->buffer[$key] .= pack("f", $value);
        $this->value[$key] = $value;
    }

    public function set_output_meta($start_time, $interval)
    {
        foreach (array_keys($this->buffer) as $key) {
            $this->meta[$key]->start_time = $start_time;
            $this->meta[$key]->interval = $interval;
            if ($this->meta[$key]->end_time == 0) $this->meta[$key]->end_time = $start_time;
        }
    }

    public function save_all()
    {
        $total_size = 0;
        foreach (array_keys($this->buffer) as $key) {
            $size = strlen($this->buffer[$key]);
            if ($size > 0) {
                $feedid = $this->params->$key;
                // Write meta data
                createmeta($this->dir, $feedid, $this->meta[$key]);
                // Write data
                fwrite($this->fh[$key], $this->buffer[$key]);
                fclose($this->fh[$key]);
                $total_size += $size;
                // Update feed last time and value
                updatetimevalue($feedid, time(), $this->value[$key]);
            }
        }
        return $total_size;
    }
}
