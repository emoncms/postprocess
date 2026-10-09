<?php
// Test runner for post processes, no database or redis required
// Usage: php tests/run.php [filter]
// Runs every test registered in tests/test_*.php, optionally only those whose name contains filter

error_reporting(E_ALL);
// Warnings and notices fail the test, except where suppressed with @
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$basedir = dirname(__DIR__);
require "$basedir/common.php";
foreach (glob("$basedir/processes/*.php") as $file) require_once $file;

// Stands in for redis, records the last time and value set for each feed
class TestRedis
{
    public $hashes = array();
    public function hMset($key, $values) { $this->hashes[$key] = $values; return true; }
}
$redis = new TestRedis();

$tests = array();
function test($name, $fn) { global $tests; $tests[$name] = $fn; }

// --------------------------------------------------------------------------------
// Feed helpers
// --------------------------------------------------------------------------------

$testdir = sys_get_temp_dir()."/postprocess-tests-".getmypid()."/";

// Create a feed with the given start time, interval and values
function feed($id, $start_time, $interval, $values = array())
{
    global $testdir;
    $meta = new stdClass();
    $meta->start_time = $start_time;
    $meta->interval = $interval;
    createmeta($testdir, $id, $meta);
    file_put_contents($testdir.$id.".dat", count($values) ? pack("f*", ...$values) : "");
}

// Create an empty feed as the feed list does when a new output feed is created
function empty_feed($id, $interval = 10)
{
    feed($id, 0, $interval);
}

// Append values to an existing feed
function append($id, $values)
{
    global $testdir;
    file_put_contents($testdir.$id.".dat", pack("f*", ...$values), FILE_APPEND);
}

function values($id)
{
    global $testdir;
    $bytes = file_get_contents($testdir.$id.".dat");
    return strlen($bytes) ? array_values(unpack("f*", $bytes)) : array();
}

function meta($id)
{
    global $testdir;
    return getmeta($testdir, $id);
}

// Run a process, default mode is reprocess all. Output printed by the process is captured
// and only shown if the test fails
function run($process, $params = array())
{
    global $testdir, $captured;
    $params = (object) array_merge(array("process_mode"=>"all", "process_start"=>0), $params);
    $class = "PostProcess_".$process;
    $p = new $class($testdir);
    ob_start();
    try {
        $result = $p->process($params);
    } finally {
        $captured .= ob_get_clean();
    }
    return $result;
}

// --------------------------------------------------------------------------------
// Assertions
// --------------------------------------------------------------------------------

class TestFailure extends Exception {}

function fmt($value)
{
    if (is_array($value)) return "[".implode(", ", array_map('fmt', $value))."]";
    if (is_float($value) && is_nan($value)) return "NAN";
    return var_export($value, true);
}

function same_value($expected, $actual)
{
    if (is_float($expected) && is_nan($expected)) return is_float($actual) && is_nan($actual);
    if (is_float($actual) && is_nan($actual)) return false;
    if (is_numeric($expected) && is_numeric($actual)) {
        // Feed values are stored as 32 bit floats
        return abs($expected - $actual) <= 1e-4 * max(1, abs($expected));
    }
    return $expected === $actual;
}

function assert_equal($expected, $actual, $message = "")
{
    // Long arrays: report the first difference
    if (is_array($expected) && is_array($actual) && max(count($expected), count($actual)) > 20) {
        $prefix = ($message ? "$message: " : "");
        foreach ($expected as $i => $value) {
            if (!array_key_exists($i, $actual)) {
                throw new TestFailure($prefix."expected ".count($expected)." values, got ".count($actual));
            }
            if (!same_value($value, $actual[$i])) {
                $from = max(0, $i - 2);
                throw new TestFailure($prefix."first difference at index $i of ".count($expected).", expected "
                    .fmt(array_slice($expected, $from, 5))." got ".fmt(array_slice($actual, $from, 5))." from index $from");
            }
        }
        if (count($actual) != count($expected)) {
            throw new TestFailure($prefix."expected ".count($expected)." values, got ".count($actual));
        }
        return;
    }
    $ok = is_array($expected)
        ? is_array($actual) && count($expected) == count($actual)
            && count(array_filter(array_map('same_value', $expected, $actual))) == count($expected)
        : same_value($expected, $actual);
    if (!$ok) throw new TestFailure(($message ? "$message: " : "")."expected ".fmt($expected).", got ".fmt($actual));
}

function assert_feed($id, $start_time, $interval, $values)
{
    $meta = meta($id);
    assert_equal($start_time, $meta->start_time, "feed $id start time");
    assert_equal($interval, $meta->interval, "feed $id interval");
    assert_equal($values, values($id), "feed $id values");
}

function assert_success($result)
{
    if (!$result["success"]) throw new TestFailure("process failed: ".$result["message"]);
}

function assert_error($result, $contains = "")
{
    if ($result["success"]) throw new TestFailure("expected process to fail, got: ".$result["message"]);
    if ($contains && strpos($result["message"], $contains) === false) {
        throw new TestFailure("expected error containing '$contains', got: ".$result["message"]);
    }
}

function assert_redis($id, $time, $value)
{
    global $redis;
    if (!isset($redis->hashes["feed:$id"])) throw new TestFailure("redis feed:$id not set");
    assert_equal($time, $redis->hashes["feed:$id"]["time"], "redis feed:$id time");
    assert_equal($value, $redis->hashes["feed:$id"]["value"], "redis feed:$id value");
}

// Compare feeds with a snapshot in tests/snapshots/$name.json. A missing snapshot is
// created from the current output, delete a snapshot to update it after an intended change.
function assert_snapshot($name, $feeds)
{
    $snapshot = array();
    foreach ($feeds as $key => $id) {
        $meta = meta($id);
        $values = values($id);
        $last = count($values) ? end($values) : NAN;
        $snapshot[$key] = array(
            "start_time"=>$meta->start_time,
            "interval"=>$meta->interval,
            "npoints"=>count($values),
            "md5"=>count($values) ? md5(pack("f*", ...$values)) : "",
            "last"=>is_nan($last) ? "NAN" : sprintf("%.6g", $last)
        );
    }
    $json = json_encode($snapshot, JSON_PRETTY_PRINT)."\n";
    $file = __DIR__."/snapshots/$name.json";
    if (!file_exists($file)) {
        if (!is_dir(__DIR__."/snapshots")) mkdir(__DIR__."/snapshots");
        file_put_contents($file, $json);
        echo "        created snapshot $name\n";
        return;
    }
    $expected = json_decode(file_get_contents($file), true);
    foreach ($expected as $key => $feed) {
        if (!isset($snapshot[$key])) throw new TestFailure("snapshot $name: missing feed $key");
        if ($snapshot[$key] != $feed) {
            throw new TestFailure("snapshot $name: feed $key expected ".json_encode($feed).", got ".json_encode($snapshot[$key]));
        }
    }
    if (count($snapshot) != count($expected)) throw new TestFailure("snapshot $name: feed count differs");
}

// --------------------------------------------------------------------------------
// Run
// --------------------------------------------------------------------------------

foreach (glob(__DIR__."/test_*.php") as $file) require $file;

$filter = $argv[1] ?? "";
$passed = 0;
$failed = 0;

foreach ($tests as $name => $fn) {
    if ($filter && strpos($name, $filter) === false) continue;

    // Fresh data directory and redis for every test
    if (is_dir($testdir)) array_map('unlink', glob($testdir."*"));
    else mkdir($testdir);
    $redis = new TestRedis();
    $captured = "";

    try {
        $fn();
        $passed++;
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name\n";
        $where = ($e instanceof TestFailure) ? "" : " (".get_class($e)." at ".basename($e->getFile()).":".$e->getLine().")";
        echo "        ".$e->getMessage()."$where\n";
        if (trim($captured) !== "") echo "        output: ".str_replace("\n", "\n                ", trim($captured))."\n";
    }
}

array_map('unlink', glob($testdir."*"));
@rmdir($testdir);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
