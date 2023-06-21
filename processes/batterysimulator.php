<?php

// Basic solar battery simulator
// Charge when excess solar available 
// Discharge when demand is greater than solar output
// Fill and empty battery as soon as possible
// No charging at night included

// These functions could ultimately be integrated into a class
class PostProcess_batterysimulator extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Battery simulator",
            "group"=>"Simulation",
            "description"=>"Basic solar battery simulator",
            "settings"=>array(
                "solar"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar feed:"),
                "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption feed:"),
                "capacity"=>array("type"=>"value", "default"=>4.4, "short"=>"Usable battery capacity in kWh"),
                "max_charge_rate"=>array("type"=>"value", "default"=>2500.0, "short"=>"Max charge rate in Watts"),
                "max_discharge_rate"=>array("type"=>"value", "default"=>2500.0, "short"=>"Max discharge rate in Watts"),
                "round_trip_efficiency"=>array("type"=>"value", "default"=>0.9, "short"=>"Round trip efficiency 0.9 = 90%"),
                "timezone"=>array("type"=>"timezone", "default"=>"Europe/London", "short"=>"Timezone for offpeak charging"),
                "offpeak_soc_target"=>array("type"=>"value", "default"=>0, "short"=>"Offpeak charging SOC target in % (0 = turn off)"),
                "offpeak_start"=>array("type"=>"value", "default"=>3, "short"=>"Offpeak charging start time"),
                "charge"=>array("type"=>"newfeed", "default"=>"battery_charge", "engine"=>5, "short"=>"Enter battery charge feed name:"),
                "discharge"=>array("type"=>"newfeed", "default"=>"battery_discharge", "engine"=>5, "short"=>"Enter battery discharge feed name:"),
                "soc"=>array("type"=>"newfeed", "default"=>"battery_soc", "engine"=>5, "short"=>"Enter battery SOC feed name:"),
                "import"=>array("type"=>"newfeed", "default"=>"import", "engine"=>5, "short"=>"Enter grid import feed name:"),
                "charge_kwh"=>array("type"=>"newfeed", "default"=>"battery_charge_kwh", "engine"=>5, "short"=>"Enter battery charge kWh feed name:"),
                "discharge_kwh"=>array("type"=>"newfeed", "default"=>"battery_discharge_kwh", "engine"=>5, "short"=>"Enter battery discharge kWh feed name:"),
                "import_kwh"=>array("type"=>"newfeed", "default"=>"import_kwh", "engine"=>5, "short"=>"Enter grid import kWh feed name:"),
                "solar_direct_kwh"=>array("type"=>"newfeed", "default"=>"solar_direct_kwh", "engine"=>5, "short"=>"Enter solar direct kwh feed name:")
            )
        );
    }

    public function process($p)
    {
        $result = $this->validate($p);
        if (!$result["success"]) return $result;

        $dir = $this->dir;
        $recalc = false;
        
        if ($p->round_trip_efficiency>1.0) $p->round_trip_efficiency = 1.0;
        if ($p->round_trip_efficiency<0.1) $p->round_trip_efficiency = 0.1;
        $single_trip_efficiency = 1.0-(1.0-$p->round_trip_efficiency)*0.5;
        
        if ($p->capacity<0.1) $p->capacity = 0.1;
        if ($p->max_charge_rate<0.0) $p->max_charge_rate = 0.0;
        if ($p->max_discharge_rate<0.0) $p->max_discharge_rate = 0.0;

        // Timezone for solar forecast and battery offpeak charging
        if (!$datetimezone = new DateTimeZone($p->timezone)) {
            return array("success"=>false,"message"=>"Invalid timezone ".$p->timezone);
        }
        $date = new DateTime();
        $date->setTimezone($datetimezone);
        
        $model = new ModelHelper($dir,$p);
        if (!$model->input('solar')) return array("success"=>false,"message"=>"Could not open solar feed");
        if (!$model->input('consumption')) return array("success"=>false,"message"=>"Could not open consumption feed");
        if (!$model->output('charge')) return array("success"=>false,"message"=>"Could not open charge feed");
        if (!$model->output('discharge')) return array("success"=>false,"message"=>"Could not open discharge feed");
        if (!$model->output('soc')) return array("success"=>false,"message"=>"Could not open soc feed");
        if (!$model->output('import')) return array("success"=>false,"message"=>"Could not open import feed");
        if (!$model->output('charge_kwh')) return array("success"=>false,"message"=>"Could not open charge_kwh feed");
        if (!$model->output('discharge_kwh')) return array("success"=>false,"message"=>"Could not open discharge_kwh feed");
        if (!$model->output('import_kwh')) return array("success"=>false,"message"=>"Could not open import_kwh feed");
        if (!$model->output('solar_direct_kwh')) return array("success"=>false,"message"=>"Could not open solar_direct_kwh feed");
        
        // Check that intervals are the same
        if ($model->meta['solar']->interval != $model->meta['consumption']->interval) {
            return array("success"=>false,"message"=>"interval of feeds do not match");
        }

        // Work out output data interval and start_time 
        $interval = $model->meta['solar']->interval;

        $start_time = $model->start_time;
        $end_time = $model->end_time;
        
        // Note: implementation only allows for same meta for all output feeds
        $model->set_output_meta($start_time,$interval);
        
        // Process new data since last run
        if (!$recalc) $start_time = $model->meta['soc']->end_time-$interval;
        if ($start_time<$model->start_time) $start_time = $model->start_time;
        
        if ($start_time==$end_time) {
            return array("success"=>true,"message"=>"Nothing to do, data already up to date");
        }
            
        $solar = 0;
        $use = 0;
        $soc = $p->capacity * 0.5;
        $charge_kwh = 0;
        $discharge_kwh = 0;
        $import_kwh = 0;
        $solar_direct_kwh = 0;

        // Get starting values
        $model->seek_to_time($start_time);    
        if ($model->meta['charge_kwh']->npoints) $charge_kwh = $model->read('charge_kwh',$charge_kwh);
        if ($model->meta['discharge_kwh']->npoints) $charge_kwh = $model->read('discharge_kwh',$charge_kwh);
        if ($model->meta['import_kwh']->npoints) $charge_kwh = $model->read('import_kwh',$charge_kwh);
        if ($model->meta['solar_direct_kwh']->npoints) $charge_kwh = $model->read('solar_direct_kwh',$charge_kwh);
        
        if ($model->meta['soc']->npoints) {
            $soc = $model->read('soc',$soc);
            $soc = $soc*0.01*$p->capacity;
        }

        // Reset again    
        $model->seek_to_time($start_time);
        
        $date->setTimestamp($start_time);
        $hour = $date->format("H")*1;
        $charging_offpeak = false;
        
        $i=0;
        for ($time=$start_time; $time<$end_time; $time+=$interval) 
        {
            // $model->seek_to_time_inputs($time); not required as input feed intervals are the same
            $last_hour = $hour;
            $date->setTimestamp($time);
            $hour = $date->format("H")*1;
            
            $solar = $model->read('solar',$solar);
            $use = $model->read('consumption',$use);
            
            // Limits
            if ($use<0) $use = 0;
            if ($solar<0) $solar = 0;
            
            $solar_direct = $solar;
            if ($solar_direct>$use) $solar_direct = $use;

            // Starts the offpeak charge session
            if ($p->offpeak_soc_target>0 && $hour==$p->offpeak_start && $last_hour!=$hour && !$charging_offpeak) {
                $charging_offpeak = true;
            }
            
            $charge = 0;
            // Charging when there is excess solar 
            if ($solar>$use) $charge = $solar-$use;
            // Offpeak / night time charge
            if ($charging_offpeak) $charge = $p->max_charge_rate;
            
            if ($charge>0) {
                if ($charge>$p->max_charge_rate) $charge = $p->max_charge_rate;
                $charge_after_loss = $charge * $single_trip_efficiency;
                $soc_inc = ($charge_after_loss * $interval) / 3600000.0;
                // Upper limit
                if (($soc+$soc_inc)>=$p->capacity) {
                    $soc_inc = $p->capacity - $soc;
                    $charge_after_loss = ($soc_inc * 3600000.0) / $interval;
                    $charge = $charge_after_loss / $single_trip_efficiency;
                }
                            
                $soc += $soc_inc;
            }
            
            // Discharge when use is more than solar
            $discharge = 0;
            if ($use>$solar && $charge==0) {
                $discharge = $use-$solar;
                if ($discharge>$p->max_discharge_rate) $discharge = $p->max_discharge_rate;
                $discharge_before_loss = $discharge / $single_trip_efficiency;
                $soc_dec = ($discharge_before_loss * $interval) / 3600000.0;
                // Lower limit
                if (($soc-$soc_dec)<=0) {
                    $soc_dec = $soc;
                    $discharge_before_loss = ($soc_dec * 3600000.0) / $interval;
                    $discharge = $discharge_before_loss * $single_trip_efficiency;
                }
                $soc -= $soc_dec;
            }
                    
            $balance = $solar - $use - $charge + $discharge;
            $import = 0;
            $export = 0;
            if ($balance>0) {
                $export = $balance;
            } else {
                $import = -1*$balance;
            }
            
            $soc_prc = 100.0*$soc/$p->capacity;

            // turn off offpeak charge if we reach 
            if ($soc_prc>=$p->offpeak_soc_target) {
                $charging_offpeak = false;
            }

            $charge_kwh += ($charge * $interval)/3600000.0;
            $discharge_kwh += ($discharge * $interval)/3600000.0;
            $import_kwh += ($import * $interval)/3600000.0;
            $solar_direct_kwh += ($solar_direct * $interval)/3600000.0;
            
            $model->write('charge',$charge);
            $model->write('discharge',$discharge);
            $model->write('soc',$soc_prc);
            $model->write('import',$import);

            $model->write('charge_kwh',$charge_kwh);
            $model->write('discharge_kwh',$discharge_kwh);
            $model->write('import_kwh',$import_kwh);
            $model->write('solar_direct_kwh',$solar_direct_kwh);
            
            $i++;
            if ($i%102400==0) echo ".";
        }
        echo "\n";
        
        $buffersize = $model->save_all();
        return array("success"=>true, "message"=>"bytes written: ".($buffersize/1024)." kb");
    }
}
