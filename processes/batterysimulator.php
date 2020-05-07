<?php

// Basic solar battery simulator
// Charge when excess solar available 
// Discharge when demand is greater than solar output
// Fill and empty battery as soon as possible
// No charging at night included

function batterysimulator($dir,$p)
{
    $recalc = false;
    
    // Params ($p for process)
    if (!isset($p->capacity)) return false;
    if (!isset($p->max_charge_rate)) return false;
    if (!isset($p->max_discharge_rate)) return false;
    if (!isset($p->round_trip_efficiency)) return false;
      
    if ($p->round_trip_efficiency>1.0) $p->round_trip_efficiency = 1.0;
    if ($p->round_trip_efficiency<0.1) $p->round_trip_efficiency = 0.1;
    $single_trip_efficiency = 1.0-(1.0-$p->round_trip_efficiency)*0.5;
    
    if ($p->capacity<0.1) $p->capacity = 0.1;
    if ($p->max_charge_rate<0.0) $p->max_charge_rate = 0.0;
    if ($p->max_discharge_rate<0.0) $p->max_discharge_rate = 0.0;
    
    $model = new ModelHelper($dir,$p);
    if (!$model->input('solar')) return false;
    if (!$model->input('consumption')) return false;
    if (!$model->output('charge')) return false;
    if (!$model->output('discharge')) return false;
    if (!$model->output('soc')) return false;
    if (!$model->output('import')) return false;
    // if (!$model->output('charge_kwh')) return false;
    // if (!$model->output('discharge_kwh')) return false;
    // if (!$model->output('import_kwh')) return false;
    
    // Check that intervals are the same
    if ($model->meta['solar']->interval != $model->meta['consumption']->interval) {
        print "ERROR: interval of feeds do not match\n";
        return false;
    }

    // Work out output data interval and start_time 
    $interval = $model->meta['solar']->interval;
    $start_time = $model->meta['solar']->start_time;
    if ($model->meta['consumption']->start_time>$start_time) $start_time = $model->meta['consumption']->start_time;
    $end_time = $model->meta['solar']->end_time;
    if ($model->meta['consumption']->end_time<$end_time) $end_time = $model->meta['consumption']->end_time;
    
    // Note: implementation only allows for same meta for all output feeds
    $model->set_output_meta($start_time,$interval);
    
    // Process new data since last run
    if (!$recalc) $start_time = $model->meta['soc']->end_time;
    
    $model->seek_to_time($start_time);
    
    $solar = 0;
    $use = 0;
    $soc = $p->capacity * 0.5;
   
    if (!$recalc) {
         $soc = $model->value['soc']*0.01*$p->capacity;
    }
    
    $i=0;
    for ($time=$start_time; $time<$end_time; $time+=$interval) 
    {
        $solar = $model->read('solar',$solar);
        $use = $model->read('consumption',$use);
        
        // Limits
        if ($use<0) $use = 0;
        if ($solar<0) $solar = 0;
                
        // Charging when there is excess solar 
        $charge = 0;
        if ($solar>$use) {
            $charge = $solar-$use;
            if ($charge>$p->max_charge_rate) $charge = $p->max_charge_rate;
            $charge_after_loss = $charge * $single_trip_efficiency;
            $soc_inc = ($charge_after_loss * $interval) / 3600000.0;
            // Upper limit
            if (($soc+$soc_inc)>$p->capacity) {
                $soc_inc = $p->capacity - $soc;
                $charge_after_loss = ($soc_inc * 3600000.0) / $interval;
                $charge = $charge_after_loss / $single_trip_efficiency;
            }
            $soc += $soc_inc;
        }
        
        // Discharge when use is more than solar
        $discharge = 0;
        $import = 0;
        if ($use>$solar) {
            $discharge = $use-$solar;
            if ($discharge>$p->max_discharge_rate) $discharge = $p->max_discharge_rate;
            $discharge_before_loss = $discharge / $single_trip_efficiency;
            $soc_dec = ($discharge_before_loss * $interval) / 3600000.0;
            // Lower limit
            if (($soc-$soc_dec)<0) {
                $soc_dec = $soc;
                $discharge_before_loss = ($soc_dec * 3600000.0) / $interval;
                $discharge = $discharge_before_loss * $single_trip_efficiency;
            }
            $soc -= $soc_dec;
            
            $import = $use - $solar - $discharge;
        }
        
        $soc_prc = 100.0*$soc/$p->capacity;
        
        $model->write('charge',$charge);
        $model->write('discharge',$discharge);
        $model->write('soc',$soc_prc);
        $model->write('import',$import);
        
        $i++;
        if ($i%102400==0) echo ".";
    }
    echo "\n";
    
    $buffersize = $model->save_all();
    print "buffer size: ".($buffersize/1024)." kb\n";
    
    return true;
}
