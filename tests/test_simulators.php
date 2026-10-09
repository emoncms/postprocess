<?php
// Tests for the simulator processes that use ModelHelper

// --------------------------------------------------------------------------------
// Carnot COP simulator
// --------------------------------------------------------------------------------

function carnot_params($extra = array())
{
    return array_merge(array(
        "heatpump_elec"=>1, "heatpump_flowT"=>2, "heatpump_returnT"=>3, "heatpump_outsideT"=>4,
        "condenser_offset"=>4.0, "refrigerant_offset"=>-6.0,
        "practical_efficiency_factor"=>0.5, "running_power_threshold"=>100.0,
        "heatpump_cop_sim"=>11, "heatpump_heat_sim"=>12, "heatpump_heat_sim_kwh"=>13, "heatpump_flowrate_sim"=>14
    ), $extra);
}

test("carnot_cop_simulator", function() {
    // Tc = 35+4+273 = 312, Tr = 5-6+273 = 272, COP = 0.5 * 312 / 40 = 3.9
    // Second datapoint is below the running power threshold
    feed(1, 3600, 3600, [1000, 50]);
    feed(2, 3600, 3600, [35, 35]);
    feed(3, 3600, 3600, [30, 30]);
    feed(4, 3600, 3600, [5, 5]);
    foreach ([11, 12, 13, 14] as $id) empty_feed($id);

    assert_success(run("carnot_cop_simulator", carnot_params()));
    assert_feed(11, 3600, 3600, [3.9, 0]);
    assert_feed(12, 3600, 3600, [3900, 0]);
    assert_feed(13, 3600, 3600, [3.9, 3.9]);
    // flow rate m3/h = heat / (4150 * dT) * 3.6
    assert_feed(14, 3600, 3600, [3900 / (4150 * 5) * 3.6, 0]);
});

test("carnot_cop_simulator continues kwh", function() {
    feed(1, 3600, 3600, [1000]);
    feed(2, 3600, 3600, [35]);
    feed(3, 3600, 3600, [30]);
    feed(4, 3600, 3600, [5]);
    foreach ([11, 12, 13, 14] as $id) empty_feed($id);
    assert_success(run("carnot_cop_simulator", carnot_params()));
    append(1, [1000]);
    append(2, [35]);
    append(3, [30]);
    append(4, [5]);
    assert_success(run("carnot_cop_simulator", carnot_params()));
    assert_feed(13, 3600, 3600, [3.9, 7.8]);
});

// --------------------------------------------------------------------------------
// Battery simulator
// --------------------------------------------------------------------------------

function battery_params($extra = array())
{
    $params = array(
        "solar"=>1, "consumption"=>2,
        "has_solar"=>1, "has_battery"=>1, "capacity"=>4.4, "max_charge_rate"=>2500.0, "max_discharge_rate"=>2500.0,
        "round_trip_efficiency"=>0.9, "timezone"=>"Europe/London", "offpeak_soc_target"=>0, "offpeak_start"=>3,
        "discharge_start"=>0, "discharge_end"=>24, "peak_export_enabled"=>0, "peak_export_start"=>16, "peak_export_end"=>19
    );
    $id = 11;
    foreach (battery_outputs() as $key) $params[$key] = $id++;
    return array_merge($params, $extra);
}

function battery_outputs()
{
    return array("soc", "power", "charge", "discharge", "charge_kwh", "discharge_kwh",
        "grid", "import", "export", "import_kwh", "export_kwh",
        "solar_to_load_kwh", "solar_to_grid_kwh", "solar_to_battery_kwh", "battery_to_load_kwh",
        "battery_to_grid_kwh", "grid_to_load_kwh", "grid_to_battery_kwh");
}

function battery_output_ids()
{
    $ids = array();
    $id = 11;
    foreach (battery_outputs() as $key) $ids[$key] = $id++;
    return $ids;
}

test("batterysimulator without battery", function() {
    feed(1, 3600, 3600, [0, 3000, 1000]);
    feed(2, 3600, 3600, [500, 1000, 1000]);
    foreach (battery_output_ids() as $id) empty_feed($id);
    assert_success(run("batterysimulator", battery_params(["has_battery"=>0])));
    $ids = battery_output_ids();
    assert_feed($ids["grid"], 3600, 3600, [500, -2000, 0]);
    assert_feed($ids["import_kwh"], 3600, 3600, [0.5, 0.5, 0.5]);
    assert_feed($ids["export_kwh"], 3600, 3600, [0, 2, 2]);
    assert_feed($ids["solar_to_load_kwh"], 3600, 3600, [0, 1, 2]);
});

test("batterysimulator charges from excess solar and discharges to load", function() {
    // 1 kWh battery, 100% efficient, starts at 50%
    feed(1, 3600, 3600, [1000, 0]);
    feed(2, 3600, 3600, [0, 1000]);
    foreach (battery_output_ids() as $id) empty_feed($id);
    assert_success(run("batterysimulator", battery_params(["capacity"=>1, "round_trip_efficiency"=>1])));
    $ids = battery_output_ids();
    // Charge limited by capacity: 0.5 kWh, then discharge 1 kWh
    assert_feed($ids["soc"], 3600, 3600, [100, 0]);
    assert_feed($ids["charge"], 3600, 3600, [500, 0]);
    assert_feed($ids["discharge"], 3600, 3600, [0, 1000]);
    assert_feed($ids["grid"], 3600, 3600, [-500, 0]);
});

// --------------------------------------------------------------------------------
// Solar battery kWh flows
// --------------------------------------------------------------------------------

function solarbattery_output_ids()
{
    return array("solar_to_load_kwh"=>11, "solar_to_grid_kwh"=>12, "solar_to_battery_kwh"=>13,
        "battery_to_load_kwh"=>14, "battery_to_grid_kwh"=>15, "grid_to_load_kwh"=>16, "grid_to_battery_kwh"=>17);
}

function solarbattery_params($extra = array())
{
    return array_merge(array("solar"=>1, "use"=>2, "grid"=>3, "battery"=>4, "strategy"=>"Solar first"), solarbattery_output_ids(), $extra);
}

// --------------------------------------------------------------------------------
// Incremental runs give the same output as a single run
// --------------------------------------------------------------------------------

// Synthetic input data from 2024-03-25 for $n steps of $interval, includes the change to
// summer time on 2024-03-31 and gaps of missing data
function sim_inputs($n, $interval = 10, $offset = 0)
{
    $start = 1711324800 + $offset;
    $data = array("solar"=>[], "use"=>[], "flowT"=>[], "returnT"=>[], "outsideT"=>[], "elec"=>[], "battery"=>[], "grid"=>[]);
    for ($i=0; $i<$n; $i++) {
        $hour = (($start + $i * $interval) % 86400) / 3600;
        $solar = max(0, 3000 * sin(($hour - 6) / 12 * M_PI));
        $use = 250 + (($i * 7919) % 1500) * (($i % 37) < 5 ? 1 : 0.1);
        $battery = $solar > $use ? -min(2000, $solar - $use) : min(1500, $use - $solar) * 0.5;
        $data["solar"][] = $solar;
        $data["use"][] = $use;
        $data["battery"][] = $battery;
        $data["grid"][] = $use - $solar - $battery;
        $data["elec"][] = ($i % 300) < 200 ? 800 + ($i % 50) * 10 : 20;
        $data["flowT"][] = 35 + ($i % 120) / 20;
        $data["returnT"][] = 31 + ($i % 120) / 25;
        $data["outsideT"][] = 5 + 4 * sin($hour / 24 * 2 * M_PI);
    }
    // Gaps of missing data
    foreach ($data as $key => $values) {
        for ($i=1000; $i<1050 && $i<$n; $i++) $data[$key][$i] = NAN;
    }
    return array($start, $interval, $data);
}

// Write the inputs up to $n datapoints into feeds $ids (key => feed id)
function sim_feeds($inputs, $ids, $n)
{
    list($start, $interval, $data) = $inputs;
    foreach ($ids as $key => $id) feed($id, $start, $interval, array_slice($data[$key], 0, $n));
}

// Run $process on all data at once and in parts of $parts datapoints, compare the outputs
function assert_incremental($process, $params, $input_ids, $output_ids, $n, $parts, $offset = 0)
{
    $inputs = sim_inputs($n, 10, $offset);

    sim_feeds($inputs, $input_ids, $n);
    foreach ($output_ids as $id) empty_feed($id);
    assert_success(run($process, $params));
    $single = array();
    foreach ($output_ids as $key => $id) $single[$key] = values($id);

    foreach ($output_ids as $id) empty_feed($id);
    foreach ($parts as $part) {
        sim_feeds($inputs, $input_ids, $part);
        assert_success(run($process, $params));
    }
    foreach ($output_ids as $key => $id) {
        assert_equal($single[$key], values($id), "$key");
    }
}

test("carnot_cop_simulator incremental", function() {
    assert_incremental("carnot_cop_simulator", carnot_params(),
        ["elec"=>1, "flowT"=>2, "returnT"=>3, "outsideT"=>4],
        ["cop"=>11, "heat"=>12, "kwh"=>13, "flowrate"=>14], 3000, [1000, 1001, 2500, 3000]);
});

test("batterysimulator incremental", function() {
    assert_incremental("batterysimulator", battery_params(),
        ["solar"=>1, "use"=>2], battery_output_ids(), 3000, [1000, 1001, 2500, 3000]);
});

test("batterysimulator incremental with run boundaries during off-peak charging", function() {
    // Off-peak window starts at 02:00, datapoint 720 each day, 8640 datapoints per day
    assert_incremental("batterysimulator", battery_params([
        "discharge_start"=>7, "discharge_end"=>22, "offpeak_soc_target"=>80, "offpeak_start"=>2,
        "peak_export_enabled"=>1, "peak_export_start"=>16, "peak_export_end"=>19
    ]), ["solar"=>1, "use"=>2], battery_output_ids(), 20000, [720, 721, 800, 9400, 9401, 9500, 20000]);
});

test("solarbatterykwh incremental", function() {
    assert_incremental("solarbatterykwh", solarbattery_params(),
        ["solar"=>1, "use"=>2, "grid"=>3, "battery"=>4], solarbattery_output_ids(), 20000, [5000, 5001, 15000, 20000]);
});

test("solarbatterykwh incremental, inputs not starting on a slot", function() {
    assert_incremental("solarbatterykwh", solarbattery_params(),
        ["solar"=>1, "use"=>2, "grid"=>3, "battery"=>4], solarbattery_output_ids(), 20000, [3000, 15000, 20000], 130);
});

test("solarbatterykwh incremental, short runs within the backcast", function() {
    assert_incremental("solarbatterykwh", solarbattery_params(),
        ["solar"=>1, "use"=>2, "grid"=>3, "battery"=>4], solarbattery_output_ids(), 6000, [100, 2000, 2001, 4000, 6000]);
});

// --------------------------------------------------------------------------------
// Snapshots of single runs over 100k datapoints, more than one block, includes the
// change to summer time
// --------------------------------------------------------------------------------

function snapshot_run($name, $process, $params, $input_ids, $output_ids)
{
    sim_feeds(sim_inputs(100000), $input_ids, 100000);
    foreach ($output_ids as $id) empty_feed($id);
    assert_success(run($process, $params));
    assert_snapshot($name, $output_ids);
}

test("batterysimulator snapshot", function() {
    snapshot_run("batterysimulator", "batterysimulator", battery_params(["discharge_start"=>7, "discharge_end"=>22]),
        ["solar"=>1, "use"=>2], battery_output_ids());
});

test("batterysimulator snapshot offpeak and peak export", function() {
    snapshot_run("batterysimulator_offpeak", "batterysimulator", battery_params([
        "discharge_start"=>7, "discharge_end"=>22, "offpeak_soc_target"=>80, "offpeak_start"=>2,
        "peak_export_enabled"=>1, "peak_export_start"=>16, "peak_export_end"=>19
    ]), ["solar"=>1, "use"=>2], battery_output_ids());
});

test("solarbatterykwh snapshot solar first", function() {
    snapshot_run("solarbatterykwh", "solarbatterykwh", solarbattery_params(),
        ["solar"=>1, "use"=>2, "grid"=>3, "battery"=>4], solarbattery_output_ids());
});

test("solarbatterykwh snapshot battery first derived grid", function() {
    $params = solarbattery_params(["strategy"=>"Battery first"]);
    unset($params["grid"]);
    snapshot_run("solarbatterykwh_derived", "solarbatterykwh", $params,
        ["solar"=>1, "use"=>2, "battery"=>4], solarbattery_output_ids());
});
