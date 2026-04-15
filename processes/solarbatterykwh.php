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
                "battery"=>array("type"=>"feed", "engine"=>5, "short"=>"Select battery power feed (W, +discharge/-charge):"),

                "strategy"=>array("type"=>"value", "default"=>"Solar first", "short"=>"Select strategy for allocating solar and battery power to load when both are available:"),

                // Output kWh flow feeds
                "solar_to_load_kwh"=>array("type"=>"newfeed", "default"=>"solar_to_load_kwh", "engine"=>5, "short"=>"Enter solar to load kWh feed name:"),
                "solar_to_grid_kwh"=>array("type"=>"newfeed", "default"=>"solar_to_grid_kwh", "engine"=>5, "short"=>"Enter solar to grid kWh feed name:"),
                "solar_to_battery_kwh"=>array("type"=>"newfeed", "default"=>"solar_to_battery_kwh", "engine"=>5, "short"=>"Enter solar to battery kWh feed name:"),
                "battery_to_load_kwh"=>array("type"=>"newfeed", "default"=>"battery_to_load_kwh", "engine"=>5, "short"=>"Enter battery to load kWh feed name:"),
                "battery_to_grid_kwh"=>array("type"=>"newfeed", "default"=>"battery_to_grid_kwh", "engine"=>5, "short"=>"Enter battery to grid kWh feed name:"),
                "grid_to_load_kwh"=>array("type"=>"newfeed", "default"=>"grid_to_load_kwh", "engine"=>5, "short"=>"Enter grid to load kWh feed name:"),
                "grid_to_battery_kwh"=>array("type"=>"newfeed", "default"=>"grid_to_battery_kwh", "engine"=>5, "short"=>"Enter grid to battery kWh feed name:"),

                // "solar_kwh"=>array("type"=>"newfeed", "default"=>"solar_kwh", "engine"=>5, "short"=>"Used for testing")
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
        $has_battery = 0;
        $num_available_input_feeds = 0;

        if ($p->strategy == "Solar first") {
            $solar_first = true;
        } else if ($p->strategy == "Battery first") {
            $solar_first = false;
        } else {
            return array("success"=>false,"message"=>"Invalid strategy value");
        }

        // Input feeds
        // The input method also determines the most recent start time and earliest end time across the feeds
        // so that we can be sure to only process time range where we have data for all feeds
        $has_solar = $model->input('solar') ? true : false;
        $has_use = $model->input('use') ? true : false;
        $has_grid = $model->input('grid') ? true : false;
        $has_battery = $model->input('battery') ? true : false;

        // Count number of feeds that we have
        if ($has_solar) $num_available_input_feeds++;
        if ($has_use) $num_available_input_feeds++;
        if ($has_grid) $num_available_input_feeds++;
        if ($has_battery) $num_available_input_feeds++;
        
        $derive = false;
        $assume_zero_solar = false;
        $assume_zero_battery = false;

        if ($num_available_input_feeds == 3) {
            if (!$has_grid) $derive = "grid";
            else if (!$has_use) $derive = "use";
            else if (!$has_solar) $derive = "solar";
            else if (!$has_battery) $derive = "battery";
        }

        else if ($num_available_input_feeds == 2) {
            if ($has_solar && $has_battery) {
                // We can't derive in this scenario, as both missing feeds (use and grid) are needed for derivation
                return array("success"=>false,"message"=>"If only solar and battery feeds provided, can't derive use or grid feed");
            }

            if ($has_solar) {
                if ($has_use) $derive = "grid";
                else if ($has_grid) $derive = "use";
                $assume_zero_battery = true; // if battery feed is missing, assume no battery power (solar-only mode)
            }

            if ($has_battery) {
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
        // 2 feeds (need at least use or grid, second can be solar or battery)
        // 1 feed (no point, can't derive the others)

        if ($num_available_input_feeds < 2) {
            return array("success"=>false,"message"=>"At least 2 input feeds required, found only ".$num_available_input_feeds);
        }

        if ($num_available_input_feeds == 2) {
            if (!$has_use && !$has_grid) {
                return array("success"=>false,"message"=>"If only 2 input feeds provided, one must be use or grid feed");
            }
            if (!$has_solar && !$has_battery) {
                return array("success"=>false,"message"=>"If only 2 input feeds provided, one must be solar or battery feed");
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
        // $solar_kwh_feed = $model->output('solar_kwh'); // used for testing

        // Get interval from first available input feed
        $interval = null;
        foreach (['solar', 'use', 'grid', 'battery'] as $feed) {
            if ($model->meta[$feed] ?? false) {
                $interval = $model->meta[$feed]->interval;
                break;
            }
        }

        // Check all available feeds have the same interval
        foreach (['solar', 'use', 'grid', 'battery'] as $feed) {
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
        $model->set_output_meta($output_start_time,$output_interval);

        // Process new data since last run and backcast 12 hours to ensure accuracy
        $backcast_time = 48 * $output_interval; 

        if (!$recalc) $start_time = $model->meta['grid_to_load_kwh']->end_time-$backcast_time; 
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
        // $solar_kwh = 0; // used for testing

        // Get starting cumulative kWh values
        $model->seek_to_time($start_time);
        if ($model->meta['solar_to_load_kwh']->npoints) $solar_to_load_kwh = $model->read('solar_to_load_kwh',$solar_to_load_kwh);
        if ($model->meta['solar_to_grid_kwh']->npoints) $solar_to_grid_kwh = $model->read('solar_to_grid_kwh',$solar_to_grid_kwh);
        if ($model->meta['solar_to_battery_kwh']->npoints) $solar_to_battery_kwh = $model->read('solar_to_battery_kwh',$solar_to_battery_kwh);
        if ($model->meta['battery_to_load_kwh']->npoints) $battery_to_load_kwh = $model->read('battery_to_load_kwh',$battery_to_load_kwh);
        if ($model->meta['battery_to_grid_kwh']->npoints) $battery_to_grid_kwh = $model->read('battery_to_grid_kwh',$battery_to_grid_kwh);
        if ($model->meta['grid_to_load_kwh']->npoints) $grid_to_load_kwh = $model->read('grid_to_load_kwh',$grid_to_load_kwh);
        if ($model->meta['grid_to_battery_kwh']->npoints) $grid_to_battery_kwh = $model->read('grid_to_battery_kwh',$grid_to_battery_kwh);
        // if ($model->meta['solar_kwh']->npoints) $solar_kwh = $model->read('solar_kwh',$solar_kwh);

        $starting_values = array(
            "solar_to_load_kwh"=>number_format($solar_to_load_kwh, 3, '.', '')*1,
            "solar_to_grid_kwh"=>number_format($solar_to_grid_kwh, 3, '.', '')*1,
            "solar_to_battery_kwh"=>number_format($solar_to_battery_kwh, 3, '.', '')*1,
            "battery_to_load_kwh"=>number_format($battery_to_load_kwh, 3, '.', '')*1,
            "battery_to_grid_kwh"=>number_format($battery_to_grid_kwh, 3, '.', '')*1,
            "grid_to_load_kwh"=>number_format($grid_to_load_kwh, 3, '.', '')*1,
            "grid_to_battery_kwh"=>number_format($grid_to_battery_kwh, 3, '.', '')*1
        );

        // Reset again
        $model->seek_to_time($start_time);

        $power_to_kwh = $interval / 3600000.0; // conversion factor from W to kWh for given interval

        $slot = floor($start_time / $output_interval) * $output_interval;

        // return array("success"=>true,"message"=>"Processing data from ".date('Y-m-d H:i:s',$start_time)." to ".date('Y-m-d H:i:s',$end_time)." with interval ".$interval." seconds, deriving missing feed: ".($derive ?? "none").", assuming zero solar: ".($assume_zero_solar ? "yes" : "no").", assuming zero battery: ".($assume_zero_battery ? "yes" : "no")."...");

        $more_to_process = false;
        $process_start_time = microtime(true);
        $i=0;

        $dp_per_feed_written = 0;

        for ($time=$start_time; $time<$end_time; $time+=$interval)
        {
            $solar         = $model->read('solar',$solar);
            $use           = $model->read('use',$use);
            $grid          = $model->read('grid',$grid);
            $battery       = $model->read('battery',$battery);

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

            $battery_to_load = 0;
            $battery_to_grid = 0;

            // -------------------------------------------------------------------------
            // Energy flow decomposition
            // -------------------------------------------------------------------------
            if ($solar_first) {
                // Solar first strategy: solar is allocated to load and battery before grid power is used

                // Solar to load: solar covers as much of load as possible
                $solar_to_load = min($solar, $use);

                // Solar to battery: if battery is charging (battery < 0), solar covers
                // charge before grid does
                $solar_to_battery = 0;
                if ($battery < 0) {
                    $solar_to_battery = min($solar - $solar_to_load, -$battery);
                }

                // Solar to grid: remainder of solar not used by load or battery
                $solar_to_grid = $solar - $solar_to_load - $solar_to_battery;

                // Battery to load and battery to grid (battery > 0 = discharging)
                if ($battery > 0) {
                    $battery_to_load = min($battery, $use - $solar_to_load);
                    $battery_to_grid = $battery - $battery_to_load;
                }
                
            } else {
                // Battery first strategy: battery is allocated to load and grid before solar is used
                // Battery to load and battery to grid (battery > 0 = discharging)
                if ($battery > 0) {
                    $battery_to_load = min($battery, $use);
                    $battery_to_grid = $battery - $battery_to_load;
                }

                // Solar to load: solar covers remaining load after battery discharge
                $solar_to_load = min($solar, max($use - $battery_to_load, 0));

                // Solar to battery: if battery is charging (battery < 0), solar covers
                // charge after grid does
                $solar_to_battery = 0;
                if ($battery < 0) {
                    $solar_to_battery = min($solar - $solar_to_load, -$battery);
                }

                // Solar to grid: remainder of solar not used by load or battery
                $solar_to_grid = $solar - $solar_to_load - $solar_to_battery;
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
            // $solar_kwh            += ($solar            * $power_to_kwh); // used for testing

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
                // $model->write('solar_kwh',$solar_kwh); // used for testing

                $dp_per_feed_written += 1;
            }

            $i++;
            if ($i%102400==0) {
                echo ".";
                // If more than 20 seconds have passed exit early
                if (microtime(true) - $process_start_time > 10) {
                    $more_to_process = true;
                    break;
                }
            }
        }
        echo "\n";

        $model->seek_to_time($start_time+$output_interval);
        $buffersize = $model->save_all();

        $elapsed_time = microtime(true) - $process_start_time;

        $end_values = array(
            "solar_to_load_kwh"=>number_format($solar_to_load_kwh, 3, '.', '')*1,
            "solar_to_grid_kwh"=>number_format($solar_to_grid_kwh, 3, '.', '')*1,
            "solar_to_battery_kwh"=>number_format($solar_to_battery_kwh, 3, '.', '')*1,
            "battery_to_load_kwh"=>number_format($battery_to_load_kwh, 3, '.', '')*1,
            "battery_to_grid_kwh"=>number_format($battery_to_grid_kwh, 3, '.', '')*1,
            "grid_to_load_kwh"=>number_format($grid_to_load_kwh, 3, '.', '')*1,
            "grid_to_battery_kwh"=>number_format($grid_to_battery_kwh, 3, '.', '')*1
        );

        return array(
            "success"=>true, 
            "message"=>"bytes written: ".($buffersize)." bytes",
            "num_available_input_feeds"=>$num_available_input_feeds,
            "derived_feed"=>$derive ?? "none",
            "elapsed_time"=>number_format($elapsed_time, 2)." seconds",
            "more_to_process"=>$more_to_process,
            "dps_processed"=>$i,
            "dp_per_feed_written"=> $dp_per_feed_written,
            "start_time"=>date('Y-m-d H:i:s',$start_time),
            "end_time"=>date('Y-m-d H:i:s',$time),
            "interval"=>$interval,
            "starting_values"=>$starting_values,
            "end_values"=>$end_values
        );
    }
}
