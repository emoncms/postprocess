<?php

// Simulates heat pump heat output and COP based on flow temperature, outside temperature and power consumption

class PostProcess_carnot_cop_simulator extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Carnot COP simulator",
            "group"=>"Simulation",
            "description"=>"Simulates heat pump heat output and COP based on flow temperature, outside temperature and power consumption",
            "settings"=>array(
                "heatpump_elec"=>array("type"=>"feed", "engine"=>5, "short"=>"Select heat pump electrical consumption feed:"),
                "heatpump_flowT"=>array("type"=>"feed", "engine"=>5, "short"=>"Select heat pump flow temperature feed:"),
                "heatpump_returnT"=>array("type"=>"feed", "engine"=>5, "short"=>"Select heat pump return temperature feed:"),
                "heatpump_outsideT"=>array("type"=>"feed", "engine"=>5, "short"=>"Select heat pump outside temperature feed:"), 
                        
                "condenser_offset"=>array("type"=>"value", "default"=>4.0, "short"=>"Condenser offset"),
                "refrigerant_offset"=>array("type"=>"value", "default"=>-6.0, "short"=>"Refrigerant offset"),
                "practical_efficiency_factor"=>array("type"=>"value", "default"=>0.5, "short"=>"Practical efficiency factor"),
                "running_power_threshold"=>array("type"=>"value", "default"=>100.0, "short"=>"running power threshold"),
                
                "heatpump_cop_sim"=>array("type"=>"newfeed", "default"=>"heatpump_cop_sim", "engine"=>5, "short"=>"Simulation of heat pump COP"),
                "heatpump_heat_sim"=>array("type"=>"newfeed", "default"=>"heatpump_heat_sim", "engine"=>5, "short"=>"Simulation of heat pump heat output"),
                "heatpump_heat_sim_kwh"=>array("type"=>"newfeed", "default"=>"heatpump_heat_sim_kwh", "engine"=>5, "short"=>"Simulation of heat pump kwh heat output"),
                "heatpump_flowrate_sim"=>array("type"=>"newfeed", "default"=>"heatpump_flowrate_sim", "engine"=>5, "short"=>"Simulation of flow rate")
            )
        );
    }

    public function process($p)
    {
        $result = $this->validate($p);
        if (!$result["success"]) return $result;

        $dir = $this->dir;
        $recalc = false;
        
        if ($p->practical_efficiency_factor>1.0) $p->practical_efficiency_factor = 1.0;
        if ($p->practical_efficiency_factor<0.0) $p->practical_efficiency_factor = 0.0;
        if ($p->running_power_threshold<0.0) $p->running_power_threshold = 0.0;
        
        $model = new ModelHelper($dir,$p);
        
        if (!$model->input('heatpump_elec')) return array("success"=>false,"message"=>"Could not open input feed: heatpump_elec");
        if (!$model->input('heatpump_flowT')) return array("success"=>false,"message"=>"Could not open input feed: heatpump_flowT");
        if (!$model->input('heatpump_returnT')) return array("success"=>false,"message"=>"Could not open input feed: heatpump_returnT");
        if (!$model->input('heatpump_outsideT')) return array("success"=>false,"message"=>"Could not open input feed: heatpump_outsideT");
        
        if (!$model->output('heatpump_cop_sim')) return array("success"=>false,"message"=>"Could not open output feed: heatpump_cop_sim");
        if (!$model->output('heatpump_heat_sim')) return array("success"=>false,"message"=>"Could not open output feed: heatpump_heat_sim");
        if (!$model->output('heatpump_heat_sim_kwh')) return array("success"=>false,"message"=>"Could not open output feed: heatpump_heat_sim_kwh");
        if (!$model->output('heatpump_flowrate_sim')) return array("success"=>false,"message"=>"Could not open output feed: heatpump_flowrate_sim");
        
        // Work out output data interval and start_time 
        $interval = $model->meta['heatpump_elec']->interval;
        
        $start_time = $model->start_time;
        $end_time = $model->end_time;
        
        // Note: implementation only allows for same meta for all output feeds
        $model->set_output_meta($start_time,$interval);
        
        // Simulation start time
        if (!$recalc) $start_time = $model->meta['heatpump_heat_sim']->end_time-$interval;
        
        if ($start_time==$end_time) {
            return array("success"=>true,"message"=>"Nothing to do, data already up to date");
        }
            
        $power = 0;
        $flowT = 0;
        $returnT = 0;
        $outsideT = 0;
        $heat_kwh = 0;

        // get cumulative kwh value
        $model->seek_to_time($start_time);
        if ($model->meta['heatpump_heat_sim']->npoints) {
            $heat_kwh = $model->read('heatpump_heat_sim_kwh',$heat_kwh);
        }
        // reset again
        $model->seek_to_time($start_time);
        
        $i=0;
        for ($time=$start_time; $time<$end_time; $time+=$interval) 
        {
            $model->seek_to_time_inputs($time);
            $power = $model->read('heatpump_elec',$power);
            $flowT = $model->read('heatpump_flowT',$flowT);
            $returnT = $model->read('heatpump_returnT',$returnT);
            $outsideT = $model->read('heatpump_outsideT',$outsideT);
            
            // Limits
            if ($power<0) $power = 0;
            
            $Tc = $flowT+$p->condenser_offset+273;
            $Tr = $outsideT+$p->refrigerant_offset+273;

            $heat = 0;
            $carnot = 0;

            if ($power>$p->running_power_threshold) {
                $dT = $Tc-$Tr;
                if ($dT>0.0) {
                    $carnot = $p->practical_efficiency_factor*($Tc / $dT);
                }
                $heat = $power * $carnot;
                if ($returnT>$flowT) $heat *= -1;
            }
            
            $dT = $flowT - $returnT;
            
            $flow_rate = 0;
            if ($dT>0.5) {
                $flow_rate = ($heat / (4150*$dT))*3.6;
            }
            if ($flow_rate<0) $flow_rate = 0;
            
            $heat_kwh += ($heat * $interval)/3600000.0;
            
            $model->write('heatpump_cop_sim',$carnot);
            $model->write('heatpump_heat_sim',$heat);
            $model->write('heatpump_heat_sim_kwh',$heat_kwh);
            $model->write('heatpump_flowrate_sim',$flow_rate);
            
            $i++;
            if ($i%102400==0) echo ".";
        }
        echo "\n";
        
        $buffersize = $model->save_all();
        return array("success"=>true, "message"=>"bytes written: ".($buffersize/1024)." kb");
    }
}