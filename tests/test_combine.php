<?php
// Tests for processes that combine several input feeds and accumulators

// --------------------------------------------------------------------------------
// Solar
// --------------------------------------------------------------------------------

test("importcalc", function() {
    feed(1, 1000, 10, [500, 100, NAN, 300]);
    feed(2, 1000, 10, [200, 300, 100, NAN]);
    empty_feed(3);
    assert_success(run("importcalc", ["use"=>1, "gen"=>2, "out"=>3]));
    // Missing generation is treated as no generation
    assert_feed(3, 1000, 10, [300, 0, NAN, 300]);
});

test("exportcalc", function() {
    feed(1, 1000, 10, [500, 100, NAN, 300]);
    feed(2, 1000, 10, [200, 300, 100, NAN]);
    empty_feed(3);
    assert_success(run("exportcalc", ["generation"=>1, "consumption"=>2, "output"=>3]));
    // Missing consumption is treated as no consumption
    assert_feed(3, 1000, 10, [300, 0, NAN, 300]);
});

test("solardirectcalc", function() {
    feed(1, 1000, 10, [500, 100, NAN, 300]);
    feed(2, 1000, 10, [200, 300, 100, NAN]);
    empty_feed(3);
    assert_success(run("solardirectcalc", ["use"=>1, "gen"=>2, "out"=>3]));
    assert_feed(3, 1000, 10, [200, 100, NAN, NAN]);
});

// --------------------------------------------------------------------------------
// Two inputs, modes
// --------------------------------------------------------------------------------

test("mergefeeds recent", function() {
    feed(1, 1000, 10, [1, 2]);
    feed(2, 1000, 10, [3, 4]);
    empty_feed(3);
    assert_success(run("mergefeeds", ["feedA"=>1, "feedB"=>2, "output"=>3]));
    append(1, [5, 6]);
    append(2, [7]);
    assert_success(run("mergefeeds", ["feedA"=>1, "feedB"=>2, "output"=>3, "process_mode"=>"recent"]));
    assert_feed(3, 1000, 10, [2, 3, 6]);
});

test("mergefeeds from", function() {
    feed(1, 1000, 10, [1, 2, 3]);
    feed(2, 1000, 10, [1, 2, 3]);
    empty_feed(3);
    assert_success(run("mergefeeds", ["feedA"=>1, "feedB"=>2, "output"=>3]));
    feed(1, 1000, 10, [10, 20, 30]);
    assert_success(run("mergefeeds", ["feedA"=>1, "feedB"=>2, "output"=>3, "process_mode"=>"from", "process_start"=>1010]));
    assert_feed(3, 1000, 10, [1, 11, 16.5]);
});

test("addfeeds later start time", function() {
    feed(1, 1000, 10, [1, 2, 3, 4]);
    feed(2, 1020, 10, [10, 20]);
    empty_feed(3);
    assert_success(run("addfeeds", ["feedA"=>1, "feedB"=>2, "output"=>3]));
    assert_feed(3, 1020, 10, [13, 24]);
});

// --------------------------------------------------------------------------------
// Accumulators
// --------------------------------------------------------------------------------

test("accumulator recent continues from last value", function() {
    feed(1, 1000, 10, [1, 2]);
    empty_feed(2);
    assert_success(run("accumulator", ["input"=>1, "output"=>2, "max_value"=>1000, "min_value"=>-1000]));
    append(1, [3]);
    assert_success(run("accumulator", ["input"=>1, "output"=>2, "max_value"=>1000, "min_value"=>-1000, "process_mode"=>"recent"]));
    assert_feed(2, 1000, 10, [1, 3, 6]);
});

test("constantflow_tokwh", function() {
    // kWh per hour = 0.001 * vhc * flow * dT = 3.6 * dT, only positive dT, NAN adds nothing
    feed(1, 3600, 3600, [20, 20, NAN, 20]);
    feed(2, 3600, 3600, [10, 25, 10, 15]);
    empty_feed(3);
    assert_success(run("constantflow_tokwh", ["vhc"=>1000, "flow"=>3.6, "tint"=>1, "text"=>2, "output"=>3]));
    assert_feed(3, 3600, 3600, [36, 36, 36, 54]);
    append(1, [20]);
    append(2, [10]);
    assert_success(run("constantflow_tokwh", ["vhc"=>1000, "flow"=>3.6, "tint"=>1, "text"=>2, "output"=>3, "process_mode"=>"recent"]));
    assert_feed(3, 3600, 3600, [36, 36, 36, 54, 90]);
});

test("liquidorairflow_tokwh", function() {
    // kWh per hour = 0.001 * vhc * flow * dT = 10 * flow
    feed(1, 3600, 3600, [1, 2, NAN, 1]);
    feed(2, 3600, 3600, [20, 20, 20, 20]);
    feed(3, 3600, 3600, [10, 10, 10, 10]);
    empty_feed(4);
    assert_success(run("liquidorairflow_tokwh", ["vhc"=>1000, "flow"=>1, "tint"=>2, "text"=>3, "output"=>4]));
    assert_feed(4, 3600, 3600, [10, 30, 30, 40]);
});

// --------------------------------------------------------------------------------
// Basic formula
// --------------------------------------------------------------------------------

test("basic_formula sum and scale", function() {
    feed(1, 1000, 10, [1, 2, NAN]);
    feed(2, 1000, 10, [10, 20, 30]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"f1+2*f2", "output"=>4]));
    assert_feed(4, 1000, 10, [21, 42, NAN]);
});

test("basic_formula max", function() {
    feed(1, 1000, 10, [5, 1, NAN]);
    feed(2, 1000, 10, [2, 3, 1]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"2*max(f1-f2,0)", "output"=>4]));
    assert_feed(4, 1000, 10, [6, 0, NAN]);
});

test("basic_formula brackets and division", function() {
    feed(1, 1000, 10, [3, 5]);
    feed(2, 1000, 10, [2, 4]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"10*(f1-1)+f2/2", "output"=>4]));
    assert_feed(4, 1000, 10, [21, 42]);
});

test("basic_formula recent", function() {
    feed(1, 1000, 10, [1, 2]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"f1*3", "output"=>4]));
    append(1, [3]);
    assert_success(run("basic_formula", ["formula"=>"f1*3", "output"=>4, "process_mode"=>"recent"]));
    assert_feed(4, 1000, 10, [3, 6, 9]);
});

test("basic_formula invalid", function() {
    feed(1, 1000, 10, [1]);
    feed(2, 1000, 10, [1]);
    empty_feed(4);
    assert_error(run("basic_formula", ["formula"=>"(f1-f2)*(f1-f2)", "output"=>4]), "could not understand");
});

test("basic_formula feed ids that are prefixes of each other", function() {
    feed(1, 1000, 10, [1, 2]);
    feed(12, 1000, 10, [10, 20]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"f1+f12", "output"=>4]));
    assert_feed(4, 1000, 10, [11, 22]);
});

test("basic_formula from", function() {
    feed(1, 1000, 10, [1, 2, 3]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"f1*3", "output"=>4]));
    feed(1, 1000, 10, [10, 20, 30]);
    assert_success(run("basic_formula", ["formula"=>"f1*3", "output"=>4, "process_mode"=>"from", "process_start"=>1010]));
    assert_feed(4, 1000, 10, [3, 60, 90]);
});

test("basic_formula multiply by zero", function() {
    feed(1, 1000, 10, [1, 2, NAN]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"f1*0+3", "output"=>4]));
    assert_feed(4, 1000, 10, [3, 3, NAN]);
});

test("basic_formula division by zero is missing", function() {
    feed(1, 1000, 10, [6, 6, 6]);
    feed(2, 1000, 10, [2, 0, NAN]);
    empty_feed(4);
    assert_success(run("basic_formula", ["formula"=>"f1/f2", "output"=>4]));
    assert_feed(4, 1000, 10, [3, NAN, NAN]);
    assert_success(run("basic_formula", ["formula"=>"f1/0+2", "output"=>4]));
    assert_feed(4, 1000, 10, [NAN, NAN, NAN]);
});

// Formula value for one datapoint, $values keyed by feed id. Reference for the block
// evaluator in basic_formula, evaluating each datapoint separately
function formula_reference($elements, $values)
{
    // sum of the products of each term
    $sum = function($terms) use ($values) {
        $s = [];
        foreach ($terms as $term) {
            $product = [];
            foreach ($term as $e) {
                $value = ($e[0] == "feed") ? $values[$e[2]] : (float) $e[2];
                if ($e[1] == "/") $value = ($value == 0) ? NAN : 1 / $value;
                if ($e[1] == "-") $value = -$value;
                $product[] = $value;
            }
            $s[] = array_product($product);
        }
        return array_sum($s);
    };
    $total = 0;
    foreach ($elements as $element) {
        $s1 = $sum([$element->scale]);
        $s2 = $sum($element->formula);
        $s3 = $sum([$element->scale_right]);
        if ($element->function == "max") {
            $arg2 = is_numeric($element->arg2) ? $element->arg2 : $sum($element->arg2);
            if (is_nan($s1) || is_nan($s2) || is_nan($s3) || is_nan($arg2)) return NAN;
            $total += $s1 * max($s2, $arg2) * $s3;
        } else {
            $total += $s1 * $s2 * $s3;
        }
    }
    return $total;
}

test("basic_formula block evaluation matches per datapoint reference", function() {
    global $testdir;
    // Random data with missing values and zeros, more than one block
    mt_srand(42);
    $n = POSTPROCESS_BLOCKSIZE + 1000;
    $data = [];
    foreach ([1, 2, 3, 12] as $id) {
        for ($i=0; $i<$n; $i++) {
            $r = mt_rand(0, 99);
            $data[$id][$i] = $r < 3 ? NAN : ($r < 8 ? 0.0 : mt_rand(-5000, 5000) / 100);
        }
        feed($id, 0, 10, $data[$id]);
    }
    // Feed values as stored
    foreach ($data as $id => $values) $data[$id] = values($id);

    $formulas = ["f1+2*f2", "2*max(f1-f2,0)", "10*(f1-1)+f2/2", "f1-0.5*f2+max(f3-21,0)",
        "1162.5*f2*(f1-11)", "f1/f2", "-f1*f2/3+4", "max(f1,f2)", "3*max(f1-f2,f3*2)/4", "f1+f12",
        "f1*0", "f1/0+2", "-(f1+f2)", "f1-f2-f3*f12/2.5", "2(f1+3)-f2", "f1/f2/f3", "max(f1-f2,f3-f12)+f1"];

    $p = new PostProcess_basic_formula($testdir);
    foreach ($formulas as $formula) {
        empty_feed(9);
        assert_success(run("basic_formula", ["formula"=>$formula, "output"=>9]));
        $parsed = $p->parse_formula($formula);
        $expected = [];
        for ($i=0; $i<$n; $i++) {
            $values = [];
            foreach ($parsed["feed_ids"] as $id) $values[$id] = $data[$id][$i];
            $expected[] = formula_reference($parsed["elements"], $values);
        }
        // Compare as stored, 32 bit floats
        $expected = array_values(unpack("f*", pack("f*", ...$expected)));
        if (pack("f*", ...$expected) !== pack("f*", ...values(9))) {
            assert_equal($expected, values(9), $formula);
            throw new TestFailure("$formula: differs from reference by less than the assert_equal tolerance");
        }
    }
});
