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
            $optional = !empty($setting['optional']);

            if (!isset($processitem->{$key}) && !$optional) {
                return array("success" => false, "message" => "setting ".$key." not set\n");
            }

            if (!isset($processitem->{$key}) && $optional) {
                continue;
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
                if (is_numeric($processitem->{$key})) {
                    $processitem->{$key} = (int) $processitem->{$key};
                }
                if (!in_array($processitem->{$key}, array('original',10,30,60,1800,3600), true)) {
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
    if (!$metafile = @fopen($dir . $id . ".meta", 'wb')) return false;
    $bytes = pack("IIII", 0, 0, $meta->interval, $meta->start_time);
    $written = fwrite($metafile, $bytes);
    fclose($metafile);
    return $written === strlen($bytes);
}

// --------------------------------------------------------------------------------
// Block based feed access
//
// FeedReader and FeedWriter read and write feeds in blocks rather than one datapoint
// at a time, which is several times faster in PHP. Errors are thrown as
// PostProcessException, which the runner reports as a failed process.
// --------------------------------------------------------------------------------

class PostProcessException extends Exception {}

// Number of datapoints to read or write at a time
const POSTPROCESS_BLOCKSIZE = 65536;

// Start time of this run of a process for the output feed meta (start_time, interval, npoints)
// recent: from the end of the output feed
// from:   from process_start, aligned to the output interval, no later than the end of the output feed
// all:    from the start of the output feed
function resolve_start_time($params, $meta)
{
    $end_time = $meta->start_time + ($meta->npoints * $meta->interval);
    $mode = isset($params->process_mode) ? $params->process_mode : 'recent';

    if ($mode == 'recent') {
        return $end_time;
    }
    if ($mode == 'from') {
        $start_time = max((int) $params->process_start, $meta->start_time);
        $start_time = $meta->start_time + floor(($start_time - $meta->start_time) / $meta->interval) * $meta->interval;
        return min($start_time, $end_time);
    }
    return $meta->start_time;
}

class FeedReader
{
    public $id;
    public $meta;
    private $fh;

    public function __construct($dir, $id)
    {
        $this->id = (int) $id;
        if (!file_exists($dir.$this->id.".meta") || !file_exists($dir.$this->id.".dat")) {
            throw new PostProcessException("feed ".$this->id." does not exist");
        }
        $this->meta = getmeta($dir, $this->id);
        $this->meta->npoints = (int) $this->meta->npoints;
        if (!$this->meta->interval) {
            throw new PostProcessException("feed ".$this->id." has no interval");
        }
        if (!$this->fh = @fopen($dir.$this->id.".dat", 'rb')) {
            throw new PostProcessException("could not open feed ".$this->id);
        }
    }

    public function __destruct()
    {
        if ($this->fh) fclose($this->fh);
    }

    // Read $n datapoints starting at position $pos, positions outside of the feed are NAN
    public function read($pos, $n)
    {
        $from = max($pos, 0);
        $to = min($pos + $n, $this->meta->npoints);

        if ($from == $pos && $to == $pos + $n) {
            fseek($this->fh, $from * 4);
            $values = array_values(unpack("f*", fread($this->fh, $n * 4)));
            if (count($values) == $n) return $values;
        }

        $values = array_fill(0, $n, NAN);
        if ($to > $from) {
            fseek($this->fh, $from * 4);
            $i = $from - $pos;
            foreach (unpack("f*", fread($this->fh, ($to - $from) * 4)) as $value) {
                $values[$i++] = $value;
            }
        }
        return $values;
    }

    // Read $n values on the timeline $start_time + i * $interval, which may differ from
    // the feed's own start time and interval. Each value covers [time, time + interval):
    // - feed interval shorter: mean of the datapoints within the interval, ignoring NAN
    // - feed interval longer: the datapoint covering the start of the interval
    public function read_time($start_time, $interval, $n)
    {
        $feed_start = $this->meta->start_time;
        $feed_interval = $this->meta->interval;

        if ($feed_interval == $interval && ($start_time - $feed_start) % $feed_interval == 0) {
            return $this->read(intdiv($start_time - $feed_start, $feed_interval), $n);
        }

        $block_start = (int) floor(($start_time - $feed_start) / $feed_interval);
        $block_end = (int) ceil(($start_time + $n * $interval - $feed_start) / $feed_interval);
        $data = $this->read($block_start, $block_end - $block_start);

        $values = array();
        for ($i=0; $i<$n; $i++) {
            $time = $start_time + $i * $interval;
            $pos_start = (int) ceil(($time - $feed_start) / $feed_interval);
            $pos_end = (int) ceil(($time + $interval - $feed_start) / $feed_interval);
            if ($pos_end <= $pos_start) {
                $pos_start = (int) floor(($time - $feed_start) / $feed_interval);
                $pos_end = $pos_start + 1;
            }
            $sum = 0;
            $count = 0;
            for ($pos=$pos_start; $pos<$pos_end; $pos++) {
                $value = $data[$pos - $block_start];
                if (!is_nan($value)) {
                    $sum += $value;
                    $count++;
                }
            }
            $values[] = $count ? $sum / $count : NAN;
        }
        return $values;
    }
}

class FeedWriter
{
    public $id;
    public $meta;
    private $dir;
    private $fh;
    private $buffer = "";
    private $pos = 0;
    private $meta_pending = false;
    private $bytes_written = 0;
    private $last_time = false;
    private $last_value = NAN;
    // Set false when rewriting part of a feed, so that the feed's last time and value are left alone
    public $update_redis = true;

    public function __construct($dir, $id)
    {
        $this->dir = $dir;
        $this->id = (int) $id;
        if (!file_exists($dir.$this->id.".meta")) {
            throw new PostProcessException("feed ".$this->id." does not exist");
        }
        if (!$this->fh = @fopen($dir.$this->id.".dat", 'c+')) {
            throw new PostProcessException("could not open feed ".$this->id." for writing");
        }
        $this->meta = getmeta($dir, $this->id);
        $this->meta->npoints = (int) $this->meta->npoints;
    }

    public function __destruct()
    {
        if ($this->fh) fclose($this->fh);
    }

    // Set the start time and interval of an output feed. An empty feed takes them (written
    // with the first data), a feed with data must already match unless $reset is set, in
    // which case existing data is discarded.
    public function init($start_time, $interval, $reset = false)
    {
        if ($this->meta->npoints > 0 && !$reset) {
            if ($this->meta->start_time != $start_time) {
                throw new PostProcessException("feed ".$this->id." start time mismatch");
            }
            if ($this->meta->interval != $interval) {
                throw new PostProcessException("feed ".$this->id." interval mismatch");
            }
            return;
        }
        if ($this->meta->npoints > 0) {
            if (!ftruncate($this->fh, 0)) throw new PostProcessException("could not truncate feed ".$this->id);
        }
        $this->meta->start_time = $start_time;
        $this->meta->interval = $interval;
        $this->meta->npoints = 0;
        $this->meta->end_time = $start_time;
        $this->pos = 0;
        $this->meta_pending = true;
    }

    // Move the write position to $time, a time beyond the end of the feed is padded with NAN
    public function seek($time)
    {
        $offset = $time - $this->meta->start_time;
        if (!$this->meta->interval || $offset < 0 || $offset % $this->meta->interval != 0) {
            throw new PostProcessException("feed ".$this->id." invalid write time $time");
        }
        $this->flush();
        $pos = intdiv($offset, $this->meta->interval);
        if ($pos > $this->meta->npoints) {
            fseek($this->fh, $this->meta->npoints * 4);
            $this->pos = $this->meta->npoints;
            $this->write_block(array_fill(0, $pos - $this->meta->npoints, NAN));
        } else {
            fseek($this->fh, $pos * 4);
            $this->pos = $pos;
        }
    }

    public function write($value)
    {
        $this->buffer .= pack("f", $value);
        if (!is_nan($value)) {
            $this->last_time = $this->meta->start_time + $this->pos * $this->meta->interval;
            $this->last_value = $value;
        }
        $this->pos++;
        if (strlen($this->buffer) >= POSTPROCESS_BLOCKSIZE * 4) $this->flush();
    }

    public function write_block($values)
    {
        $n = count($values);
        if (!$n) return;
        $this->buffer .= pack("f*", ...$values);
        for ($i=$n-1; $i>=0; $i--) {
            if (!is_nan($values[$i])) {
                $this->last_time = $this->meta->start_time + ($this->pos + $i) * $this->meta->interval;
                $this->last_value = $values[$i];
                break;
            }
        }
        $this->pos += $n;
        if (strlen($this->buffer) >= POSTPROCESS_BLOCKSIZE * 4) $this->flush();
    }

    // Time of the next datapoint to be written
    public function time()
    {
        return $this->meta->start_time + $this->pos * $this->meta->interval;
    }

    public function flush()
    {
        if ($this->buffer === "") return;
        if ($this->meta_pending) {
            if (!createmeta($this->dir, $this->id, $this->meta)) {
                throw new PostProcessException("could not write meta for feed ".$this->id);
            }
            $this->meta_pending = false;
        }
        $length = strlen($this->buffer);
        $written = fwrite($this->fh, $this->buffer);
        if ($written !== $length) {
            throw new PostProcessException("feed ".$this->id.": wrote $written of $length bytes, disk full?");
        }
        $this->bytes_written += $length;
        $this->meta->npoints = max($this->meta->npoints, $this->pos);
        $this->meta->end_time = $this->meta->start_time + $this->meta->npoints * $this->meta->interval;
        $this->buffer = "";
    }

    // Flush remaining data and close. $truncate removes any existing data after the last
    // datapoint written, use when reprocessing so that no stale data is left at the end.
    // Updates the feed's last time and value in redis. Returns the number of bytes written.
    public function close($truncate = false)
    {
        $this->flush();
        if ($truncate && $this->pos < $this->meta->npoints) {
            if (!ftruncate($this->fh, $this->pos * 4)) {
                throw new PostProcessException("could not truncate feed ".$this->id);
            }
            $this->meta->npoints = $this->pos;
            $this->meta->end_time = $this->time();
        }
        fclose($this->fh);
        $this->fh = false;
        if ($this->update_redis && $this->last_time !== false) {
            updatetimevalue($this->id, $this->last_time, $this->last_value);
        }
        return $this->bytes_written;
    }

    // Value of the datapoint at $time, NAN if outside of the feed
    public function value_at($time)
    {
        $this->flush();
        $offset = $time - $this->meta->start_time;
        if ($offset < 0 || $offset % $this->meta->interval != 0) return NAN;
        $pos = intdiv($offset, $this->meta->interval);
        if ($pos >= $this->meta->npoints) return NAN;
        fseek($this->fh, $pos * 4);
        $value = unpack("f", fread($this->fh, 4))[1];
        fseek($this->fh, $this->pos * 4);
        return $value;
    }

    public function last_time() { return $this->last_time; }
    public function last_value() { return $this->last_value; }
}

// --------------------------------------------------------------------------------
// Base classes for processes that transform each value of a feed
//
// Subclasses implement value($value), called for each datapoint that is not NAN, or
// values($values) to handle a block of datapoints at a time, e.g. to keep state between
// datapoints. reset() is called at the start of each run to reset any such state.
// Process settings are available as $this->params.
// --------------------------------------------------------------------------------

abstract class ValueProcess extends PostProcess_common
{
    protected $params;

    protected function reset() {}

    protected function value($value)
    {
        return $value;
    }

    protected function values($values)
    {
        foreach ($values as $i => $value) {
            if (!is_nan($value)) $values[$i] = $this->value($value);
        }
        return $values;
    }
}

// Writes the transformed values of feed setting "input" to feed setting "output", with the
// same start time and interval. Supports recent, from and all process modes, reprocessing
// all replaces the output feed.
abstract class MapProcess extends ValueProcess
{
    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;

        try {
            $input = new FeedReader($this->dir, $params->input);
            $output = new FeedWriter($this->dir, $params->output);
            $interval = $input->meta->interval;
            $output->init($input->meta->start_time, $interval, $params->process_mode == 'all');

            $start_time = resolve_start_time($params, $output->meta);
            $end_time = $input->meta->end_time;
            if ($start_time >= $end_time) {
                return array("success"=>true, "message"=>"output feed already up to date");
            }

            $this->params = $params;
            $this->reset();
            $output->seek($start_time);
            while (($time = $output->time()) < $end_time) {
                $n = (int) min(POSTPROCESS_BLOCKSIZE, ($end_time - $time) / $interval);
                $output->write_block($this->values($input->read_time($time, $interval, $n)));
            }
            // Reprocessing removes any output data beyond the end of the input
            $bytes = $output->close($params->process_mode != 'recent');

        } catch (PostProcessException $e) {
            return array("success"=>false, "message"=>$e->getMessage());
        }
        return array("success"=>true, "message"=>"bytes written: ".$bytes.", last time value: ".$output->last_time()." ".$output->last_value());
    }
}

// Transforms the values of feed setting "feedid" in place. Always processes the whole feed,
// only blocks containing changed values are written.
abstract class InPlaceProcess extends ValueProcess
{
    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;

        try {
            $input = new FeedReader($this->dir, $params->feedid);
            $output = new FeedWriter($this->dir, $params->feedid);
            $output->update_redis = false;
            $npoints = $input->meta->npoints;
            if ($npoints == 0) {
                return array("success"=>false, "message"=>"feed is empty");
            }

            $this->params = $params;
            $this->reset();
            $changed = 0;
            for ($pos=0; $pos<$npoints; $pos+=POSTPROCESS_BLOCKSIZE) {
                $values = $input->read($pos, min(POSTPROCESS_BLOCKSIZE, $npoints - $pos));
                $new_values = $this->values($values);
                $block_changed = 0;
                foreach ($values as $i => $value) {
                    $new_value = $new_values[$i];
                    if (is_nan($value) ? !is_nan($new_value) : (is_nan($new_value) || $new_value != $value)) {
                        $block_changed++;
                    }
                }
                if ($block_changed) {
                    $output->seek($input->meta->start_time + $pos * $input->meta->interval);
                    $output->write_block($new_values);
                    $changed += $block_changed;
                }
            }
            $output->close();

        } catch (PostProcessException $e) {
            return array("success"=>false, "message"=>$e->getMessage());
        }
        return array("success"=>true, "message"=>"modified: ".$changed." datapoints, ".round(($changed / $npoints) * 100)."%");
    }
}

// Combines input feeds into an output feed on a common timeline: the longest input interval,
// from the latest input start time to the earliest input end time. Inputs with a shorter
// interval are averaged into each output interval. Only complete output intervals are
// written so that recent mode picks up partially recorded intervals on the next run.
// Supports recent, from and all process modes, reprocessing all replaces the output feed.
//
// Subclasses set $inputs and $output to the feed setting keys and implement
// row($a, $b, ...), called with one value per input in the order of $inputs (including NAN)
// and returning the output value. Settings are available as $this->params and the output
// interval as $this->interval. reset($previous) is called at the start of each run with the
// output value before the start time (NAN if none), e.g. to continue an accumulation.
abstract class CombineProcess extends PostProcess_common
{
    protected $inputs = array();
    protected $output = "output";
    protected $params;
    protected $interval;

    protected function reset($previous) {}

    // Feed ids of the inputs keyed by name, from the $inputs settings by default
    protected function input_feeds($params)
    {
        $feeds = array();
        foreach ($this->inputs as $key) $feeds[$key] = $params->$key;
        return $feeds;
    }

    // Output values for a block of input values, one array per input
    protected function block($blocks)
    {
        $out = array();
        $columns = array_values($blocks);
        switch (count($columns)) {
            case 1:
                foreach ($columns[0] as $a) $out[] = $this->row($a);
                break;
            case 2:
                foreach ($columns[0] as $i => $a) $out[] = $this->row($a, $columns[1][$i]);
                break;
            case 3:
                foreach ($columns[0] as $i => $a) $out[] = $this->row($a, $columns[1][$i], $columns[2][$i]);
                break;
            default:
                $n = count($columns[0]);
                for ($i=0; $i<$n; $i++) $out[] = $this->row(...array_column($columns, $i));
        }
        return $out;
    }

    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;

        try {
            $this->params = $params;
            $inputs = array();
            foreach ($this->input_feeds($params) as $key => $feedid) {
                $inputs[$key] = new FeedReader($this->dir, $feedid);
            }
            if (!count($inputs)) {
                return array("success"=>false, "message"=>"no input feeds");
            }
            $output = new FeedWriter($this->dir, $params->{$this->output});

            $interval = 0;
            $start_time = 0;
            $end_time = PHP_INT_MAX;
            foreach ($inputs as $input) {
                $interval = max($interval, $input->meta->interval);
                $start_time = max($start_time, $input->meta->start_time);
                $end_time = min($end_time, $input->meta->end_time);
            }
            $start_time = (int) floor($start_time / $interval) * $interval;
            $end_time = $start_time + (int) floor(($end_time - $start_time) / $interval) * $interval;
            $this->interval = $interval;

            $output->init($start_time, $interval, $params->process_mode == 'all');
            $time = resolve_start_time($params, $output->meta);
            if ($time >= $end_time) {
                return array("success"=>true, "message"=>"output feed already up to date");
            }

            $this->reset($output->value_at($time - $interval));
            $output->seek($time);
            while (($time = $output->time()) < $end_time) {
                $n = (int) min(POSTPROCESS_BLOCKSIZE, ($end_time - $time) / $interval);
                $blocks = array();
                foreach ($inputs as $key => $input) {
                    $blocks[$key] = $input->read_time($time, $interval, $n);
                }
                $output->write_block($this->block($blocks));
            }
            $bytes = $output->close($params->process_mode != 'recent');

        } catch (PostProcessException $e) {
            return array("success"=>false, "message"=>$e->getMessage());
        }
        return array("success"=>true, "message"=>"bytes written: ".$bytes.", last time value: ".$output->last_time()." ".$output->last_value());
    }
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
output of a basic formula for one timestep
the formula is described by the $elements array > a third dimensionnal array > datas are on level 2
for a given level 1, we operate multiplication/division within level 2 elements and give a sign to the result
the final result is the addition of the whole
a formula element is a 3 elements vector :
0 -> type of data ie "feed" ou "value"
1 -> operator
2 -> value or feed number
values : feed values for this timestep keyed by feed number
*/
function bfo($elements, $values): float|int
{
    $s = [];
    foreach ($elements as $element) {
        $product = [];
        foreach ($element as $e) {
            $value = NAN;
            if ($e[0] == "feed") $value = $values[$e[2]];
            if ($e[0] == "value") $value = $e[2];
            if (!is_nan(num: $value) && $value != 0) {
                if ($e[1] == "/") $value = 1 / $value;
                if ($e[1] == "-") $value = -$value;
            }
            $product[] = $value;
        }
        if (!in_array(needle: NAN, haystack: $product)) {
            $s[] = array_product(array: $product);
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
        $this->meta[$key] = false;
        $this->fh[$key] = false;
        $this->value[$key] = false;

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
        if (!$result = $this->load($key, 'rb')) return false;
        if ($this->start_time === false) $this->start_time = $this->meta[$key]->start_time;
        else if ($this->meta[$key]->start_time > $this->start_time) $this->start_time = $this->meta[$key]->start_time;
        if ($this->end_time === false) $this->end_time = $this->meta[$key]->end_time;
        else if ($this->meta[$key]->end_time < $this->end_time) $this->end_time = $this->meta[$key]->end_time;
        return true;
    }

    public function output($key)
    {
        if (!$this->load($key, 'c+')) return false;
        $this->buffer[$key] = "";
        return true;
    }

    public function read($key, $value)
    {
        if (!$this->fh[$key]) return $value;
        $bytes = fread($this->fh[$key], 4);
        if (strlen($bytes) != 4) return $value;
        $tmp = unpack("f", $bytes);
        if ($tmp[1] !== null && !is_nan($tmp[1])) $value = $tmp[1];
        return $value;
    }

    public function seek_to_time($time)
    {
        foreach (array_keys($this->fh) as $key) {
            if (!$this->meta[$key]) continue;
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
        // Skip outputs that are not loaded
        if (empty($this->fh[$key])) return;
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
