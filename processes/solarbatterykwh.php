<?php

// Solar battery kWh energy flow calculator
// Calculates energy flow breakdown from measured solar, use, grid and battery_power feeds
// Follows the same calculation logic as solartest.js
// Sign convention: battery_power positive = discharge, negative = charge
//                  grid positive = import, negative = export

class PostProcess_solarbatterykwh extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Solar battery kWh flows",
            "group"=>"Solar",
            "description"=>"Calculate solar/battery/grid energy flow breakdown from measured feeds",
            "settings"=>array(
                // Input feeds
                "solar"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar power feed (W):"),
                "use"=>array("type"=>"feed", "engine"=>5, "short"=>"Select use/consumption power feed (W):"),
                "grid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select grid power feed (W, +import/-export):"),
                "battery_power"=>array("type"=>"feed", "engine"=>5, "short"=>"Select battery power feed (W, +discharge/-charge):"),

                // Output kWh flow feeds
                "solar_to_load_kwh"=>array("type"=>"newfeed", "default"=>"solar_to_load_kwh", "engine"=>5, "short"=>"Enter solar to load kWh feed name:"),
                "solar_to_grid_kwh"=>array("type"=>"newfeed", "default"=>"solar_to_grid_kwh", "engine"=>5, "short"=>"Enter solar to grid kWh feed name:"),
                "solar_to_battery_kwh"=>array("type"=>"newfeed", "default"=>"solar_to_battery_kwh", "engine"=>5, "short"=>"Enter solar to battery kWh feed name:"),
                "battery_to_load_kwh"=>array("type"=>"newfeed", "default"=>"battery_to_load_kwh", "engine"=>5, "short"=>"Enter battery to load kWh feed name:"),
                "battery_to_grid_kwh"=>array("type"=>"newfeed", "default"=>"battery_to_grid_kwh", "engine"=>5, "short"=>"Enter battery to grid kWh feed name:"),
                "grid_to_load_kwh"=>array("type"=>"newfeed", "default"=>"grid_to_load_kwh", "engine"=>5, "short"=>"Enter grid to load kWh feed name:"),
                "grid_to_battery_kwh"=>array("type"=>"newfeed", "default"=>"grid_to_battery_kwh", "engine"=>5, "short"=>"Enter grid to battery kWh feed name:")
            )
        );
    }

    public function process($p)
    {
        // $result = $this->validate($p);
        // if (!$result["success"]) return $result;

        $dir = $this->dir;
        $recalc = false;

        $model = new ModelHelper($dir,$p);


        $has_solar = 0;
        $has_use = 0;
        $has_grid = 0;
        $has_battery_power = 0;
        $num_available_input_feeds = 0;

        // Input feeds
        $has_solar = $model->input('solar') ? true : false;
        $has_use = $model->input('use') ? true : false;
        $has_grid = $model->input('grid') ? true : false;
        $has_battery_power = $model->input('battery_power') ? true : false;

        if ($has_solar) $num_available_input_feeds++;
        if ($has_use) $num_available_input_feeds++;
        if ($has_grid) $num_available_input_feeds++;
        if ($has_battery_power) $num_available_input_feeds++;

        $derive = false;
        $assume_zero_solar = false;
        $assume_zero_battery = false;

        if ($num_available_input_feeds == 3) {
            if (!$has_grid) $derive = "grid";
            else if (!$has_use) $derive = "use";
            else if (!$has_solar) $derive = "solar";
            else if (!$has_battery_power) $derive = "battery";
        }

        else if ($num_available_input_feeds == 2) {
            if ($has_solar && $has_battery_power) {
                // We can't derive in this scenario, as both missing feeds (use and grid) are needed for derivation
                return array("success"=>false,"message"=>"If only solar and battery_power feeds provided, can't derive use or grid feed");
            }

            if ($has_solar) {
                if ($has_use) $derive = "grid";
                else if ($has_grid) $derive = "use";
                $assume_zero_battery = true; // if battery feed is missing, assume no battery power (solar-only mode)
            }

            if ($has_battery_power) {
                if ($has_use) $derive = "grid";
                else if ($has_grid) $derive = "use";
                $assume_zero_solar = true; // if solar feed is missing, assume no solar generation (battery-only mode)
            }
        }

        else if ($num_available_input_feeds == 1) {
            if ($has_use) $derive = "grid";
            else if ($has_grid) $derive = "use";
            $assume_zero_solar = true;
            $assume_zero_battery = true;
        }



        // 4 feeds (one more than needed)
        // 3 feeds (can derive 4th)
        // 2 feeds (need at least use or grid, second can be solar or battery_power)
        // 1 feed (no point, can't derive the others)

        if ($num_available_input_feeds < 2) {
            return array("success"=>false,"message"=>"At least 2 input feeds required, found only ".$num_available_input_feeds);
        }

        if ($num_available_input_feeds == 2) {
            if (!$has_use && !$has_grid) {
                return array("success"=>false,"message"=>"If only 2 input feeds provided, one must be use or grid feed");
            }
            if (!$has_solar && !$has_battery_power) {
                return array("success"=>false,"message"=>"If only 2 input feeds provided, one must be solar or battery_power feed");
            }
        }

        // Output feeds
        $solar_to_load_kwh_feed = $model->output('solar_to_load_kwh');
        $solar_to_grid_kwh_feed = $model->output('solar_to_grid_kwh');
        $solar_to_battery_kwh_feed = $model->output('solar_to_battery_kwh');
        $battery_to_load_kwh_feed = $model->output('battery_to_load_kwh');
        $battery_to_grid_kwh_feed = $model->output('battery_to_grid_kwh');
        $grid_to_load_kwh_feed = $model->output('grid_to_load_kwh');
        $grid_to_battery_kwh_feed = $model->output('grid_to_battery_kwh');

        // Get interval from first available input feed
        $interval = null;
        foreach (['solar', 'use', 'grid', 'battery_power'] as $feed) {
            if ($model->meta[$feed] ?? false) {
                $interval = $model->meta[$feed]->interval;
                break;
            }
        }

        // Check all available feeds have the same interval
        foreach (['solar', 'use', 'grid', 'battery_power'] as $feed) {
            if (($model->meta[$feed] ?? false) && $model->meta[$feed]->interval != $interval) {
                return array("success"=>false,"message"=>"interval of {$feed} feed does not match other feeds");
            }
        }

        $start_time = $model->start_time;
        $end_time = $model->end_time;

        // Switch to 15 minute intervals for the output feeds.
        $output_interval = 900;
        $output_start_time = floor($start_time / $output_interval) * $output_interval;

        // Note: implementation only allows for same meta for all output feeds
        $model->set_output_meta($output_start_time+$output_interval,$output_interval);

        // Process new data since last run
        if (!$recalc) $start_time = $model->meta['solar_to_load_kwh']->end_time-$output_interval;
        if ($start_time<$model->start_time) $start_time = $model->start_time;

        if ($start_time>=$end_time) {
            return array("success"=>true,"message"=>"Nothing to do, data already up to date");
        }

        $solar = 0;
        $use = 0;
        $grid = 0;
        $battery = 0;

        $solar_to_load_kwh = 0;
        $solar_to_grid_kwh = 0;
        $solar_to_battery_kwh = 0;
        $battery_to_load_kwh = 0;
        $battery_to_grid_kwh = 0;
        $grid_to_load_kwh = 0;
        $grid_to_battery_kwh = 0;

        // Get starting cumulative kWh values
        $model->seek_to_time($start_time);
        if ($model->meta['solar_to_load_kwh']->npoints) $solar_to_load_kwh = $model->read('solar_to_load_kwh',$solar_to_load_kwh);
        if ($model->meta['solar_to_grid_kwh']->npoints) $solar_to_grid_kwh = $model->read('solar_to_grid_kwh',$solar_to_grid_kwh);
        if ($model->meta['solar_to_battery_kwh']->npoints) $solar_to_battery_kwh = $model->read('solar_to_battery_kwh',$solar_to_battery_kwh);
        if ($model->meta['battery_to_load_kwh']->npoints) $battery_to_load_kwh = $model->read('battery_to_load_kwh',$battery_to_load_kwh);
        if ($model->meta['battery_to_grid_kwh']->npoints) $battery_to_grid_kwh = $model->read('battery_to_grid_kwh',$battery_to_grid_kwh);
        if ($model->meta['grid_to_load_kwh']->npoints) $grid_to_load_kwh = $model->read('grid_to_load_kwh',$grid_to_load_kwh);
        if ($model->meta['grid_to_battery_kwh']->npoints) $grid_to_battery_kwh = $model->read('grid_to_battery_kwh',$grid_to_battery_kwh);

        // Reset again
        $model->seek_to_time($start_time);

        $power_to_kwh = $interval / 3600000.0; // conversion factor from W to kWh for given interval

        $slot = floor($start_time / $output_interval) * $output_interval;

        $i=0;
        for ($time=$start_time; $time<$end_time; $time+=$interval)
        {
            $solar         = $model->read('solar',$solar);
            $use           = $model->read('use',$use);
            $grid          = $model->read('grid',$grid);
            $battery       = $model->read('battery_power',$battery);

            // Limits
            if ($solar < 0) $solar = 0; // negative solar doesn't make sense
            if ($use < 0) $use = 0; // negative use doesn't make sense

            if ($assume_zero_solar) $solar = 0;
            if ($assume_zero_battery) $battery = 0;

            if ($derive === "grid") {
                $grid = $use - $solar - $battery;
            } else if ($derive === "use") {
                $use = $solar + $battery + $grid;
            } else if ($derive === "solar") {
                $solar = $use - $battery - $grid;
            } else if ($derive === "battery") {
                $battery = $use - $solar - $grid;
            }


            $import_power = ($grid > 0) ? $grid : 0;

            // -------------------------------------------------------------------------
            // Energy flow decomposition
            // -------------------------------------------------------------------------

            // Solar to load: solar covers as much of load as possible
            $solar_to_load = min($solar, $use);

            // Solar to battery: if battery is charging (battery_power < 0), solar covers
            // charge before grid does
            $solar_to_battery = 0;
            if ($battery < 0) {
                $solar_to_battery = min($solar - $solar_to_load, -$battery);
            }

            // Solar to grid: remainder of solar not used by load or battery
            $solar_to_grid = $solar - $solar_to_load - $solar_to_battery;

            // Battery to load and battery to grid (battery_power > 0 = discharging)
            $battery_to_load = 0;
            $battery_to_grid = 0;
            if ($battery > 0) {
                $battery_to_load = min($battery, $use - $solar_to_load);
                $battery_to_grid = $battery - $battery_to_load;
            }

            // Grid to load and grid to battery
            $grid_to_load = 0;
            $grid_to_battery = 0;
            if ($import_power > 0) {
                $grid_to_load = min($import_power, $use - $solar_to_load - $battery_to_load);
                $grid_to_battery = min($import_power - $grid_to_load, $battery < 0 ? -$battery - $solar_to_battery : 0);
            }

            // -------------------------------------------------------------------------

            $solar_to_load_kwh    += ($solar_to_load    * $power_to_kwh);
            $solar_to_grid_kwh    += ($solar_to_grid    * $power_to_kwh);
            $solar_to_battery_kwh += ($solar_to_battery * $power_to_kwh);
            $battery_to_load_kwh  += ($battery_to_load  * $power_to_kwh);
            $battery_to_grid_kwh  += ($battery_to_grid  * $power_to_kwh);
            $grid_to_load_kwh     += ($grid_to_load     * $power_to_kwh);
            $grid_to_battery_kwh  += ($grid_to_battery  * $power_to_kwh);

            $last_slot = $slot;
            $slot = floor($time / $output_interval) * $output_interval;

            if ($slot != $last_slot) {
                $model->write('solar_to_load_kwh',$solar_to_load_kwh);
                $model->write('solar_to_grid_kwh',$solar_to_grid_kwh);
                $model->write('solar_to_battery_kwh',$solar_to_battery_kwh);
                $model->write('battery_to_load_kwh',$battery_to_load_kwh);
                $model->write('battery_to_grid_kwh',$battery_to_grid_kwh);
                $model->write('grid_to_load_kwh',$grid_to_load_kwh);
                $model->write('grid_to_battery_kwh',$grid_to_battery_kwh);
            }

            $i++;
            if ($i%102400==0) echo ".";
        }
        echo "\n";

        $buffersize = $model->save_all();
        return array(
            "success"=>true, 
            "message"=>"bytes written: ".($buffersize/1024)." kb",
            "num_available_input_feeds"=>$num_available_input_feeds
        );
    }
}
