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
        $result = $this->validate($p);
        if (!$result["success"]) return $result;

        $dir = $this->dir;
        $recalc = false;

        $model = new ModelHelper($dir,$p);

        // Input feeds
        if (!$model->input('solar')) return array("success"=>false,"message"=>"Could not open solar feed");
        if (!$model->input('use')) return array("success"=>false,"message"=>"Could not open use feed");
        if (!$model->input('grid')) return array("success"=>false,"message"=>"Could not open grid feed");
        if (!$model->input('battery_power')) return array("success"=>false,"message"=>"Could not open battery_power feed");

        // Output feeds
        if (!$model->output('solar_to_load_kwh')) return array("success"=>false,"message"=>"Could not open solar_to_load_kwh feed");
        if (!$model->output('solar_to_grid_kwh')) return array("success"=>false,"message"=>"Could not open solar_to_grid_kwh feed");
        if (!$model->output('solar_to_battery_kwh')) return array("success"=>false,"message"=>"Could not open solar_to_battery_kwh feed");
        if (!$model->output('battery_to_load_kwh')) return array("success"=>false,"message"=>"Could not open battery_to_load_kwh feed");
        if (!$model->output('battery_to_grid_kwh')) return array("success"=>false,"message"=>"Could not open battery_to_grid_kwh feed");
        if (!$model->output('grid_to_load_kwh')) return array("success"=>false,"message"=>"Could not open grid_to_load_kwh feed");
        if (!$model->output('grid_to_battery_kwh')) return array("success"=>false,"message"=>"Could not open grid_to_battery_kwh feed");

        // Check that intervals are the same across all input feeds
        $interval = $model->meta['solar']->interval;
        if ($model->meta['use']->interval != $interval) {
            return array("success"=>false,"message"=>"interval of use feed does not match solar feed");
        }
        if ($model->meta['grid']->interval != $interval) {
            return array("success"=>false,"message"=>"interval of grid feed does not match solar feed");
        }
        if ($model->meta['battery_power']->interval != $interval) {
            return array("success"=>false,"message"=>"interval of battery_power feed does not match solar feed");
        }

        $start_time = $model->start_time;
        $end_time = $model->end_time;

        // Note: implementation only allows for same meta for all output feeds
        $model->set_output_meta($start_time,$interval);

        // Process new data since last run
        if (!$recalc) $start_time = $model->meta['solar_to_load_kwh']->end_time-$interval;
        if ($start_time<$model->start_time) $start_time = $model->start_time;

        if ($start_time==$end_time) {
            return array("success"=>true,"message"=>"Nothing to do, data already up to date");
        }

        $solar = 0;
        $use = 0;
        $grid = 0;
        $battery_power = 0;

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

        $i=0;
        for ($time=$start_time; $time<$end_time; $time+=$interval)
        {
            $solar         = $model->read('solar',$solar);
            $use           = $model->read('use',$use);
            $grid          = $model->read('grid',$grid);
            $battery_power = $model->read('battery_power',$battery_power);

            // Limits
            if ($solar < 0) $solar = 0; // negative solar doesn't make sense
            if ($use < 0) $use = 0; // negative use doesn't make sense


            // Override use via conservation of energy
            $grid = $use - $solar - $battery_power;

            $import_power = ($grid > 0) ? $grid : 0;

            // -------------------------------------------------------------------------
            // Energy flow decomposition
            // -------------------------------------------------------------------------

            // Solar to load: solar covers as much of load as possible
            $solar_to_load = min($solar, $use);

            // Solar to battery: if battery is charging (battery_power < 0), solar covers
            // charge before grid does
            $solar_to_battery = 0;
            if ($battery_power < 0) {
                $solar_to_battery = min($solar - $solar_to_load, -$battery_power);
            }

            // Solar to grid: remainder of solar not used by load or battery
            $solar_to_grid = $solar - $solar_to_load - $solar_to_battery;

            // Battery to load and battery to grid (battery_power > 0 = discharging)
            $battery_to_load = 0;
            $battery_to_grid = 0;
            if ($battery_power > 0) {
                $battery_to_load = min($battery_power, $use - $solar_to_load);
                $battery_to_grid = $battery_power - $battery_to_load;
            }

            // Grid to load and grid to battery
            $grid_to_load = 0;
            $grid_to_battery = 0;
            if ($import_power > 0) {
                $grid_to_load = min($import_power, $use - $solar_to_load - $battery_to_load);
                $grid_to_battery = min($import_power - $grid_to_load, $battery_power < 0 ? -$battery_power - $solar_to_battery : 0);
            }

            // -------------------------------------------------------------------------

            $solar_to_load_kwh    += ($solar_to_load    * $power_to_kwh);
            $solar_to_grid_kwh    += ($solar_to_grid    * $power_to_kwh);
            $solar_to_battery_kwh += ($solar_to_battery * $power_to_kwh);
            $battery_to_load_kwh  += ($battery_to_load  * $power_to_kwh);
            $battery_to_grid_kwh  += ($battery_to_grid  * $power_to_kwh);
            $grid_to_load_kwh     += ($grid_to_load     * $power_to_kwh);
            $grid_to_battery_kwh  += ($grid_to_battery  * $power_to_kwh);

            $model->write('solar_to_load_kwh',$solar_to_load_kwh);
            $model->write('solar_to_grid_kwh',$solar_to_grid_kwh);
            $model->write('solar_to_battery_kwh',$solar_to_battery_kwh);
            $model->write('battery_to_load_kwh',$battery_to_load_kwh);
            $model->write('battery_to_grid_kwh',$battery_to_grid_kwh);
            $model->write('grid_to_load_kwh',$grid_to_load_kwh);
            $model->write('grid_to_battery_kwh',$grid_to_battery_kwh);

            $i++;
            if ($i%102400==0) echo ".";
        }
        echo "\n";

        $buffersize = $model->save_all();
        return array("success"=>true, "message"=>"bytes written: ".($buffersize/1024)." kb");
    }
}
