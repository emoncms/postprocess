<?php
// Tests for the shared feed access code in common.php

function meta_of($start_time, $interval, $npoints)
{
    return (object) array("start_time"=>$start_time, "interval"=>$interval, "npoints"=>$npoints);
}

test("resolve_start_time", function() {
    $meta = meta_of(1000, 10, 5);
    assert_equal(1000, resolve_start_time((object) ["process_mode"=>"all"], $meta), "all");
    assert_equal(1050, resolve_start_time((object) ["process_mode"=>"recent"], $meta), "recent");
    assert_equal(1050, resolve_start_time((object) [], $meta), "default is recent");
    assert_equal(1020, resolve_start_time((object) ["process_mode"=>"from", "process_start"=>1025], $meta), "from aligns down");
    assert_equal(1000, resolve_start_time((object) ["process_mode"=>"from", "process_start"=>0], $meta), "from before start");
    assert_equal(1050, resolve_start_time((object) ["process_mode"=>"from", "process_start"=>9999], $meta), "from after end");
    assert_equal(1000, resolve_start_time((object) ["process_mode"=>"recent"], meta_of(1000, 10, 0)), "recent empty");
});

// --------------------------------------------------------------------------------
// FeedReader
// --------------------------------------------------------------------------------

test("FeedReader read", function() {
    global $testdir;
    feed(1, 1000, 10, [1, 2, NAN, 4]);
    $feed = new FeedReader($testdir, 1);
    assert_equal(4, $feed->meta->npoints);
    assert_equal([1, 2, NAN, 4], $feed->read(0, 4));
    assert_equal([2, NAN], $feed->read(1, 2));
    // Outside of the feed
    assert_equal([NAN, NAN, 1, 2], $feed->read(-2, 4));
    assert_equal([4, NAN, NAN], $feed->read(3, 3));
    assert_equal([NAN, NAN], $feed->read(10, 2));
});

test("FeedReader missing feed", function() {
    global $testdir;
    try {
        new FeedReader($testdir, 99);
    } catch (PostProcessException $e) {
        return;
    }
    throw new TestFailure("expected PostProcessException");
});

test("FeedReader read_time same interval", function() {
    global $testdir;
    feed(1, 1000, 10, [1, 2, 3, 4]);
    $feed = new FeedReader($testdir, 1);
    assert_equal([2, 3], $feed->read_time(1010, 10, 2));
    assert_equal([NAN, 1], $feed->read_time(990, 10, 2));
});

test("FeedReader read_time same interval not aligned", function() {
    global $testdir;
    // Datapoints at 1005, 1015, ... each interval takes the datapoint within it
    feed(1, 1005, 10, [1, 2, 3, 4]);
    $feed = new FeedReader($testdir, 1);
    assert_equal([1, 2, 3, 4, NAN], $feed->read_time(1000, 10, 5));
});

test("FeedReader read_time downsample averages ignoring NAN", function() {
    global $testdir;
    feed(1, 1000, 10, [1, 2, 3, NAN, NAN, NAN, 7]);
    $feed = new FeedReader($testdir, 1);
    assert_equal([1.5, 3, NAN, 7], $feed->read_time(1000, 20, 4));
});

test("FeedReader read_time upsample holds value", function() {
    global $testdir;
    feed(1, 1000, 30, [1, 2]);
    $feed = new FeedReader($testdir, 1);
    assert_equal([NAN, 1, 1, 1, 2, 2, 2, NAN], $feed->read_time(990, 10, 8));
});

// --------------------------------------------------------------------------------
// FeedWriter
// --------------------------------------------------------------------------------

test("FeedWriter new feed writes meta with data", function() {
    global $testdir;
    empty_feed(2);
    $out = new FeedWriter($testdir, 2);
    $out->init(1000, 10);
    $out->seek(1000);
    // Meta is not written until there is data
    assert_equal(0, meta(2)->start_time);
    $out->write(1);
    $out->write_block([2, NAN]);
    assert_equal(12, $out->close());
    assert_feed(2, 1000, 10, [1, 2, NAN]);
    // Last non NAN value
    assert_redis(2, 1010, 2);
});

test("FeedWriter init on feed with data", function() {
    global $testdir;
    feed(2, 1000, 10, [1, 2, 3]);
    $out = new FeedWriter($testdir, 2);
    $out->init(1000, 10);
    try {
        $out->init(1000, 20);
        throw new TestFailure("expected interval mismatch");
    } catch (PostProcessException $e) {
        assert_equal(true, strpos($e->getMessage(), "interval mismatch") !== false);
    }
    $out->init(2000, 20, true);
    $out->seek(2000);
    $out->write(5);
    $out->close();
    assert_feed(2, 2000, 20, [5]);
});

test("FeedWriter overwrite in place", function() {
    global $testdir;
    feed(2, 1000, 10, [1, 2, 3, 4]);
    $out = new FeedWriter($testdir, 2);
    $out->seek(1010);
    $out->write_block([20, 30]);
    $out->close();
    assert_feed(2, 1000, 10, [1, 20, 30, 4]);
});

test("FeedWriter truncate removes stale data", function() {
    global $testdir;
    feed(2, 1000, 10, [1, 2, 3, 4]);
    $out = new FeedWriter($testdir, 2);
    $out->seek(1010);
    $out->write(20);
    $out->close(true);
    assert_feed(2, 1000, 10, [1, 20]);
});

test("FeedWriter seek beyond end pads with NAN", function() {
    global $testdir;
    feed(2, 1000, 10, [1]);
    $out = new FeedWriter($testdir, 2);
    $out->seek(1030);
    $out->write(4);
    $out->close();
    assert_feed(2, 1000, 10, [1, NAN, NAN, 4]);
});

test("FeedWriter rejects unaligned seek", function() {
    global $testdir;
    feed(2, 1000, 10, [1]);
    $out = new FeedWriter($testdir, 2);
    foreach ([1005, 990] as $time) {
        try {
            $out->seek($time);
            throw new TestFailure("expected invalid write time for $time");
        } catch (PostProcessException $e) {}
    }
});

test("FeedWriter flushes large writes in blocks", function() {
    global $testdir;
    empty_feed(2);
    $n = POSTPROCESS_BLOCKSIZE * 2 + 10;
    $out = new FeedWriter($testdir, 2);
    $out->init(0, 10);
    $out->seek(0);
    for ($i=0; $i<$n; $i++) $out->write($i);
    $out->close();
    $values = values(2);
    assert_equal($n, count($values));
    assert_equal($n - 1, $values[$n - 1]);
});
