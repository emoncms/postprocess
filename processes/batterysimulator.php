<?php

// Basic solar battery simulator
// Charge when excess solar available 
// Discharge when demand is greater than solar output
// Fill and empty battery as soon as possible
// No charging at night included

// Uncomment to test:
// require "../common.php";
// $p = new stdClass();
// $p->capacity = 7.0;
// $p->solar = ... 
// battery_simulator("/var/opt/emoncms/phpfina/",$p)

function batterysimulator($dir,$p)
{
    $recalc = false;

    // Params ($p for process)
    if (!isset($p->capacity)) return false;
    if (!isset($p->max_charge_rate)) return false;
    if (!isset($p->max_discharge_rate)) return false;
    if (!isset($p->round_trip_efficiency)) return false;
    // Input feeds
    if (!isset($p->solar)) return false;
    if (!isset($p->consumption)) return false;
    // Output feeds
    if (!isset($p->charge)) return false;
    if (!isset($p->discharge)) return false;
    if (!isset($p->soc)) return false;
    if (!isset($p->import)) return false;

    if ($p->round_trip_efficiency>1.0) $p->round_trip_efficiency = 1.0;
    if ($p->round_trip_efficiency<0.1) $p->round_trip_efficiency = 0.1;
    $single_trip_efficiency = 1.0-(1.0-$p->round_trip_efficiency)*0.5;
    
    if ($p->capacity<0.1) $p->capacity = 0.1;
    if ($p->max_charge_rate<0.0) $p->max_charge_rate = 0.0;
    if ($p->max_discharge_rate<0.0) $p->max_discharge_rate = 0.0;
    
    // Check that feeds exist
    if (!file_exists($dir.$p->solar.".meta")) {
        print "input file ".$p->solar.".meta does not exist\n";
        return false;
    }
    if (!file_exists($dir.$p->consumption.".meta")) {
        print "input file ".$p->consumption.".meta does not exist\n";
        return false;
    }
    if (!file_exists($dir.$p->charge.".meta")) {
        print "output file ".$p->charge.".meta does not exist\n";
        return false;
    }
    if (!file_exists($dir.$p->discharge.".meta")) {
        print "output file ".$p->discharge.".meta does not exist\n";
        return false;
    }
    if (!file_exists($dir.$p->soc.".meta")) {
        print "output file ".$p->soc.".meta does not exist\n";
        return false;
    }
    if (!file_exists($dir.$p->import.".meta")) {
        print "output file ".$p->import.".meta does not exist\n";
        return false;
    }
    
    // Fetch input feed meta
    $solar_meta = getmeta($dir,$p->solar);
    $use_meta = getmeta($dir,$p->consumption);
    // Check that intervals are the same
    if ($solar_meta->interval != $use_meta->interval) {
        print "ERROR: interval of feeds do not match\n";
        return false;
    }

    // Output data interval and start_time 
    $interval = $solar_meta->interval;
    $start_time = $solar_meta->start_time;
    if ($use_meta->start_time>$start_time) $start_time = $use_meta->start_time;
    $end_time = $solar_meta->end_time;
    if ($use_meta->end_time<$end_time) $end_time = $use_meta->end_time;
    // Assign to output meta class
    $out_meta = new stdClass();
    $out_meta->start_time = $start_time;
    $out_meta->interval = $interval;
    // Create output feed meta files
    createmeta($dir,$p->charge,$out_meta);
    createmeta($dir,$p->discharge,$out_meta);
    createmeta($dir,$p->soc,$out_meta);
    createmeta($dir,$p->import,$out_meta);
    
    // Process new data since last run
    $soc_meta = getmeta($dir,$p->soc);
    if (!$recalc) $start_time = $soc_meta->end_time;
    
    // Open input feed data files
    if (!$solar_fh = @fopen($dir.$p->solar.".dat", 'rb')) {
        echo "ERROR: could not open $dir ".$p->solar.".dat\n";
        return false;
    }
    if (!$use_fh = @fopen($dir.$p->consumption.".dat", 'rb')) {
        echo "ERROR: could not open $dir ".$p->consumption.".dat\n";
        return false;
    }
    // Open output files so that we can read last value if needed
    if (!$charge_fh = @fopen($dir.$p->charge.".dat", 'c+')) {
        echo "ERROR: could not open $dir ".$p->charge.".dat\n";
        return false;
    }
    if (!$discharge_fh = @fopen($dir.$p->discharge.".dat", 'c+')) {
        echo "ERROR: could not open $dir ".$p->discharge.".dat\n";
        return false;
    }
    if (!$soc_fh = @fopen($dir.$p->soc.".dat", 'c+')) {
        echo "ERROR: could not open $dir ".$p->soc.".dat\n";
        return false;
    }
    if (!$import_fh = @fopen($dir.$p->import.".dat", 'c+')) {
        echo "ERROR: could not open $dir ".$p->import.".dat\n";
        return false;
    }
    
    // Seek to starting positions
    $solar_pos = floor(($start_time - $solar_meta->start_time) / $solar_meta->interval);
    $use_pos = floor(($start_time - $use_meta->start_time) / $use_meta->interval);
    $output_pos = floor(($start_time - $soc_meta->start_time) / $soc_meta->interval);
    fseek($solar_fh,$solar_pos*4);
    fseek($use_fh,$use_pos*4);
    
    $solar = 0;
    $use = 0;
    $soc = $p->capacity * 0.5; 
    
    if (!$recalc && $output_pos!=0) {
        // Read in last battery state of charge
        fseek($soc_fh,($output_pos-1)*4);
        $tmp = unpack("f",fread($soc_fh,4));
        if (!is_nan($tmp[1])) $soc = $tmp[1]*0.01*$p->capacity;
    } else {
        $output_pos = 0;
    }

    fseek($charge_fh,$output_pos*4);
    fseek($discharge_fh,$output_pos*4);
    fseek($soc_fh,$output_pos*4);
    fseek($import_fh,$output_pos*4);
    
    $charge_buffer = "";
    $discharge_buffer = "";
    $soc_buffer = "";
    $import_buffer = "";
    
    $i=0;
    for ($time=$start_time; $time<$end_time; $time+=$interval) 
    {
        $tmp = unpack("f",fread($solar_fh,4));
        if (!is_nan($tmp[1])) $solar = $tmp[1];
        
        $tmp = unpack("f",fread($use_fh,4));
        if (!is_nan($tmp[1])) $use = $tmp[1];
        
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
        
        $charge_buffer .= pack("f",$charge);
        $discharge_buffer .= pack("f",$discharge);
        $soc_buffer .= pack("f",$soc_prc);
        $import_buffer .= pack("f",$import);
        
        $i++;
        if ($i%102400==0) echo ".";
    }
    echo "\n";
    
    $buffersize = strlen($soc_buffer)*4;
    print "buffer size: ".$buffersize."\n";
    
    if ($buffersize>0) {
        fwrite($charge_fh,$charge_buffer);
        updatetimevalue($p->charge,$time,$charge);
        
        fwrite($discharge_fh,$discharge_buffer);
        updatetimevalue($p->discharge,$time,$discharge);
        
        fwrite($soc_fh,$soc_buffer);
        updatetimevalue($p->soc,$time,$soc);
        
        fwrite($import_fh,$import_buffer);
        updatetimevalue($p->import,$time,$import);
    }
    fclose($charge_fh);
    fclose($discharge_fh);  
    fclose($soc_fh);
    fclose($import_fh);
    
    return true;
}
