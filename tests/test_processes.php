<?php
// Tests for the behaviour of existing processes

// --------------------------------------------------------------------------------
// One input, one output
// --------------------------------------------------------------------------------

test("scalefeed all", function() {
    feed(1, 1000, 10, [1, 2, NAN, 4]);
    empty_feed(2);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]));
    assert_feed(2, 1000, 10, [2, 4, NAN, 8]);
    // Time of the last datapoint
    assert_redis(2, 1030, 8);
});

test("scalefeed recent appends new data only", function() {
    feed(1, 1000, 10, [1, 2, NAN, 4]);
    empty_feed(2);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]));
    // Changes to existing input data are not picked up in recent mode
    feed(1, 1000, 10, [100, 100, 100, 100, 5, 6]);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2, "process_mode"=>"recent"]));
    assert_feed(2, 1000, 10, [2, 4, NAN, 8, 10, 12]);
});

test("scalefeed recent up to date", function() {
    feed(1, 1000, 10, [1, 2]);
    empty_feed(2);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]));
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2, "process_mode"=>"recent"]));
    assert_feed(2, 1000, 10, [2, 4]);
});

test("scalefeed from", function() {
    feed(1, 1000, 10, [1, 2, 3, 4]);
    empty_feed(2);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]));
    feed(1, 1000, 10, [10, 20, 30, 40]);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2, "process_mode"=>"from", "process_start"=>1020]));
    assert_feed(2, 1000, 10, [2, 4, 60, 80]);
});

test("scalefeed rejects interval mismatch", function() {
    feed(1, 1000, 10, [1, 2]);
    feed(2, 1000, 20, [1]);
    assert_error(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]), "interval mismatch");
});

test("offsetfeed", function() {
    feed(1, 1000, 10, [1, NAN, 3]);
    empty_feed(2);
    assert_success(run("offsetfeed", ["input"=>1, "offset"=>-1, "output"=>2]));
    assert_feed(2, 1000, 10, [0, NAN, 2]);
});

test("allowpositive", function() {
    feed(1, 1000, 10, [-1, 2, NAN, -3]);
    empty_feed(2);
    assert_success(run("allowpositive", ["input"=>1, "output"=>2]));
    assert_feed(2, 1000, 10, [0, 2, NAN, 0]);
});

test("allownegative", function() {
    feed(1, 1000, 10, [-1, 2, NAN, -3]);
    empty_feed(2);
    assert_success(run("allownegative", ["input"=>1, "output"=>2]));
    assert_feed(2, 1000, 10, [-1, 0, NAN, -3]);
});

test("scalefeed from after end of output continues from output end", function() {
    feed(1, 1000, 10, [1, 2]);
    empty_feed(2);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]));
    append(1, [3, 4]);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2, "process_mode"=>"from", "process_start"=>1030]));
    assert_feed(2, 1000, 10, [2, 4, 6, 8]);
});

test("scalefeed all removes output beyond end of input", function() {
    feed(1, 1000, 10, [1, 2]);
    feed(2, 1000, 10, [9, 9, 9, 9]);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]));
    assert_feed(2, 1000, 10, [2, 4]);
});

test("scalefeed recent keeps output beyond end of input", function() {
    feed(1, 1000, 10, [1, 2]);
    feed(2, 1000, 10, [9, 9, 9, 9]);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2, "process_mode"=>"recent"]));
    assert_feed(2, 1000, 10, [9, 9, 9, 9]);
});

test("scalefeed across block boundaries", function() {
    $n = POSTPROCESS_BLOCKSIZE * 2 + 3;
    $values = range(0, $n - 1);
    feed(1, 0, 10, $values);
    empty_feed(2);
    assert_success(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>2]));
    assert_feed(2, 0, 10, array_map(function($v) { return $v * 2; }, $values));
    assert_redis(2, ($n - 1) * 10, ($n - 1) * 2);
});

test("scalefeed missing output feed", function() {
    feed(1, 1000, 10, [1]);
    assert_error(run("scalefeed", ["input"=>1, "scale"=>2, "output"=>5]), "does not exist");
});

// --------------------------------------------------------------------------------
// Accumulators
// --------------------------------------------------------------------------------

test("powertokwh all", function() {
    // 1 hour interval so that W = Wh per datapoint, NAN holds the last power value
    feed(1, 3600, 3600, [1000, 1000, NAN, 2000]);
    empty_feed(2);
    assert_success(run("powertokwh", ["input"=>1, "output"=>2, "max_power"=>1000000, "min_power"=>-1000000]));
    assert_feed(2, 3600, 3600, [1, 2, 3, 5]);
    assert_redis(2, 3600 * 5, 5);
});

test("powertokwh recent continues from last value", function() {
    feed(1, 3600, 3600, [1000, 1000]);
    empty_feed(2);
    assert_success(run("powertokwh", ["input"=>1, "output"=>2, "max_power"=>1000000, "min_power"=>-1000000]));
    append(1, [500, 500]);
    assert_success(run("powertokwh", ["input"=>1, "output"=>2, "max_power"=>1000000, "min_power"=>-1000000, "process_mode"=>"recent"]));
    assert_feed(2, 3600, 3600, [1, 2, 2.5, 3]);
});

test("powertokwh from", function() {
    feed(1, 3600, 3600, [1000, 1000, 1000, 1000]);
    empty_feed(2);
    assert_success(run("powertokwh", ["input"=>1, "output"=>2, "max_power"=>1000000, "min_power"=>-1000000]));
    feed(1, 3600, 3600, [1000, 1000, 2000, 2000]);
    assert_success(run("powertokwh", ["input"=>1, "output"=>2, "max_power"=>1000000, "min_power"=>-1000000, "process_mode"=>"from", "process_start"=>3600 * 3]));
    assert_feed(2, 3600, 3600, [1, 2, 4, 6]);
});

test("accumulator", function() {
    feed(1, 1000, 10, [1, 2, NAN, 3]);
    empty_feed(2);
    assert_success(run("accumulator", ["input"=>1, "output"=>2, "max_value"=>1000, "min_value"=>-1000]));
    assert_feed(2, 1000, 10, [1, 3, 5, 8]);
});

// --------------------------------------------------------------------------------
// Two inputs
// --------------------------------------------------------------------------------

test("addfeeds", function() {
    feed(1, 1000, 10, [1, 2, NAN, 4]);
    feed(2, 1000, 10, [10, NAN, NAN, 40]);
    empty_feed(3);
    assert_success(run("addfeeds", ["feedA"=>1, "feedB"=>2, "output"=>3]));
    assert_feed(3, 1000, 10, [11, 2, NAN, 44]);
});

test("addfeeds different intervals uses longest", function() {
    feed(1, 1000, 10, [1, 2, 3, 4]);
    feed(2, 1000, 20, [10, 20]);
    empty_feed(3);
    assert_success(run("addfeeds", ["feedA"=>1, "feedB"=>2, "output"=>3]));
    assert_feed(3, 1000, 20, [11, 23]);
});

test("addfeeds stops at end of shortest input", function() {
    feed(1, 1000, 10, [1, 2, 3, 4]);
    feed(2, 1000, 10, [10, 20]);
    empty_feed(3);
    assert_success(run("addfeeds", ["feedA"=>1, "feedB"=>2, "output"=>3]));
    assert_feed(3, 1000, 10, [11, 22]);
    append(2, [30]);
    assert_success(run("addfeeds", ["feedA"=>1, "feedB"=>2, "output"=>3, "process_mode"=>"recent"]));
    assert_feed(3, 1000, 10, [11, 22, 33]);
});

test("mergefeeds", function() {
    feed(1, 1000, 10, [1, 2, NAN, 4]);
    feed(2, 1000, 10, [10, NAN, NAN, 40]);
    empty_feed(3);
    assert_success(run("mergefeeds", ["feedA"=>1, "feedB"=>2, "output"=>3]));
    assert_feed(3, 1000, 10, [5.5, 2, NAN, 22]);
});

// --------------------------------------------------------------------------------
// In place
// --------------------------------------------------------------------------------

test("removenan interpolates gaps", function() {
    feed(1, 1000, 10, [1, NAN, NAN, 4, NAN]);
    assert_success(run("removenan", ["feedid"=>1]));
    // Trailing NAN has no end value to interpolate to
    assert_feed(1, 1000, 10, [1, 2, 3, 4, NAN]);
});

test("limit_morethan_lessthan", function() {
    feed(1, 1000, 10, [-50, 5, NAN, 50]);
    assert_success(run("limit_morethan_lessthan", ["feedid"=>1, "morethan"=>10, "lessthan"=>-10]));
    assert_feed(1, 1000, 10, [-10, 5, NAN, 10]);
});

test("in place processes leave redis alone", function() {
    global $redis;
    feed(1, 1000, 10, [-50, 5]);
    assert_success(run("limit_morethan_lessthan", ["feedid"=>1, "morethan"=>10, "lessthan"=>-10]));
    assert_equal(0, count($redis->hashes));
});

test("in place process on empty feed", function() {
    empty_feed(1);
    assert_error(run("limit_morethan_lessthan", ["feedid"=>1, "morethan"=>10, "lessthan"=>-10]), "empty");
});

test("in place process ignores process mode", function() {
    feed(1, 1000, 10, [-50, 5, 50]);
    $result = run("limit_morethan_lessthan", ["feedid"=>1, "morethan"=>10, "lessthan"=>-10, "process_mode"=>"recent"]);
    assert_success($result);
    assert_equal("modified: 2 datapoints, 67%", $result["message"]);
    assert_feed(1, 1000, 10, [-10, 5, 10]);
});

test("remove_morethan_lessthan", function() {
    feed(1, 1000, 10, [-50, 5, NAN, 50]);
    assert_success(run("remove_morethan_lessthan", ["feedid"=>1, "morethan"=>10, "lessthan"=>-10]));
    assert_feed(1, 1000, 10, [NAN, 5, NAN, NAN]);
});

test("to_signed", function() {
    feed(1, 1000, 10, [100, 65535, NAN, 32768, 32767]);
    assert_success(run("to_signed", ["feedid"=>1]));
    assert_feed(1, 1000, 10, [100, -1, NAN, -32768, 32767]);
});

test("removenan_lastvalue", function() {
    feed(1, 1000, 10, [NAN, 1, NAN, NAN, 4, NAN]);
    assert_success(run("removenan_lastvalue", ["feedid"=>1]));
    // Leading NAN has no last value
    assert_feed(1, 1000, 10, [NAN, 1, 1, 1, 4, 4]);
});

test("removenan_lastvalue carries last value across blocks and resets between runs", function() {
    global $testdir;
    $n = POSTPROCESS_BLOCKSIZE + 2;
    $values = array_fill(0, $n, NAN);
    $values[POSTPROCESS_BLOCKSIZE - 1] = 7;
    feed(1, 0, 10, $values);
    feed(2, 0, 10, [NAN, 3]);

    // The runner reuses process objects between runs
    $p = new PostProcess_removenan_lastvalue($testdir);
    $params = (object) ["process_mode"=>"all", "process_start"=>0];
    $params->feedid = 1;
    ob_start();
    assert_success($p->process($params));
    $params->feedid = 2;
    assert_success($p->process($params));
    ob_end_clean();

    $result = values(1);
    assert_equal([7, 7, 7], array_slice($result, POSTPROCESS_BLOCKSIZE - 1));
    assert_equal(true, is_nan($result[0]));
    // Not carried over from the previous run
    assert_equal([NAN, 3], values(2));
});

// --------------------------------------------------------------------------------
// Backfill
// --------------------------------------------------------------------------------

test("backfillfeed appends averaged source data after target end", function() {
    // Target 20s with a gap before its end that must be left alone
    feed(1, 1000, 20, [1, 2, NAN, 4]);
    // Source 10s, not aligned with the target, ends at 1165
    $source = [];
    for ($i=0; $i<16; $i++) $source[] = 100 + $i;
    $source[10] = NAN;
    $source[11] = NAN;
    feed(2, 1005, 10, $source);

    assert_success(run("backfillfeed", ["feed"=>1, "source"=>2]));
    assert_feed(1, 1000, 20, [1, 2, NAN, 4, 108.5, NAN, 112.5, 114.5]);
    assert_redis(1, 1140, 114.5);
});

test("backfillfeed waits for complete intervals", function() {
    feed(1, 1000, 20, [1]);
    feed(2, 1000, 10, [1, 2, 3, 4, 5]);
    assert_success(run("backfillfeed", ["feed"=>1, "source"=>2]));
    assert_feed(1, 1000, 20, [1, 3.5]);
    append(2, [6]);
    assert_success(run("backfillfeed", ["feed"=>1, "source"=>2, "process_mode"=>"recent"]));
    assert_feed(1, 1000, 20, [1, 3.5, 5.5]);
    assert_success(run("backfillfeed", ["feed"=>1, "source"=>2]));
    assert_feed(1, 1000, 20, [1, 3.5, 5.5]);
});

test("backfillfeed source with longer interval", function() {
    feed(1, 1000, 10, [1]);
    feed(2, 1000, 20, [5, 6, 7]);
    assert_success(run("backfillfeed", ["feed"=>1, "source"=>2]));
    assert_feed(1, 1000, 10, [1, 5, 6, 6, 7, 7]);
});

test("backfillfeed rejects same feed", function() {
    feed(1, 1000, 10, [1]);
    assert_error(run("backfillfeed", ["feed"=>1, "source"=>1]), "different");
});

test("backfillfeed rejects empty target", function() {
    empty_feed(1, 20);
    feed(2, 1000, 10, [1, 2]);
    assert_error(run("backfillfeed", ["feed"=>1, "source"=>2]), "empty");
});

test("backfillfeed missing source fails cleanly", function() {
    feed(1, 1000, 10, [1]);
    $result = (function() {
        try { return run("backfillfeed", ["feed"=>1, "source"=>99]); }
        catch (PostProcessException $e) { return array("success"=>false, "message"=>$e->getMessage()); }
    })();
    assert_error($result, "does not exist");
});
