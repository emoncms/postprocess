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
    $efficiency = 0.95; // single trip / one way efficiency

    // Params ($p for process)
    if (!isset($p->capacity)) return false;
    // Input feeds
    if (!isset($p->solar)) return false;
    if (!isset($p->consumption)) return false;
    // Output feeds
    if (!isset($p->charge)) return false;
    if (!isset($p->discharge)) return false;
    if (!isset($p->soc)) return false;
    if (!isset($p->import)) return false;
    
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
    
    // Open input feed data files
    if (!$solar_fh = @fopen($dir.$p->solar.".dat", 'rb')) {
        echo "ERROR: could not open $dir ".$p->solar.".dat\n";
        return false;
    }
    if (!$use_fh = @fopen($dir.$p->consumption.".dat", 'rb')) {
        echo "ERROR: could not open $dir ".$p->consumption.".dat\n";
        return false;
    }
    
    // Seek to starting positions
    $solar_pos = floor(($start_time - $solar_meta->start_time) / $solar_meta->interval);
    $use_pos = floor(($start_time - $use_meta->start_time) / $use_meta->interval);
    fseek($solar_fh,$solar_pos*4);
    fseek($use_fh,$use_pos*4);
    
    $solar = 0;
    $use = 0;
    $soc = $p->capacity * 0.5; 
    
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
            $charge_after_loss = $charge * $efficiency;
            $soc_inc = ($charge_after_loss * $interval) / 3600000.0;
            // Upper limit
            if (($soc+$soc_inc)>$p->capacity) {
                $soc_inc = $p->capacity - $soc;
                $charge_after_loss = ($soc_inc * 3600000.0) / $interval;
                $charge = $charge_after_loss / $efficiency;
            }
            $soc += $soc_inc;
        }
        
        // Discharge when use is more than solar
        $discharge = 0;
        $import = 0;
        if ($use>$solar) {
            $discharge = $use-$solar;
            $discharge_before_loss = $discharge / $efficiency;
            $soc_dec = ($discharge_before_loss * $interval) / 3600000.0;
            // Lower limit
            if (($soc-$soc_dec)<0) {
                $soc_dec = $soc;
                $discharge_before_loss = ($soc_dec * 3600000.0) / $interval;
                $discharge = $discharge_before_loss * $efficiency;
            }
            $soc -= $soc_dec;
            
            $import = $use - $solar - $discharge;
        }
        
        $charge_buffer .= pack("f",$charge);
        $discharge_buffer .= pack("f",$discharge);
        $soc_buffer .= pack("f",$soc);
        $import_buffer .= pack("f",$import);
    } 

    if (!$fh = @fopen($dir.$p->charge.".dat", 'wb')) {
        echo "ERROR: could not open $dir ".$p->charge.".dat\n";
        return false;
    }
    fwrite($fh,$charge_buffer);
    fclose($fh);
    updatetimevalue($p->charge,$time,$charge);
    
    if (!$fh = @fopen($dir.$p->discharge.".dat", 'wb')) {
        echo "ERROR: could not open $dir ".$p->discharge.".dat\n";
        return false;
    }
    fwrite($fh,$discharge_buffer);
    fclose($fh);
    updatetimevalue($p->discharge,$time,$discharge);
    
    if (!$fh = @fopen($dir.$p->soc.".dat", 'wb')) {
        echo "ERROR: could not open $dir ".$p->soc.".dat\n";
        return false;
    }
    fwrite($fh,$soc_buffer);
    fclose($fh);
    updatetimevalue($p->soc,$time,$soc);
    
    if (!$fh = @fopen($dir.$p->import.".dat", 'wb')) {
        echo "ERROR: could not open $dir ".$p->import.".dat\n";
        return false;
    }
    fwrite($fh,$import_buffer);
    fclose($fh);
    updatetimevalue($p->import,$time,$import);
        
    return true;
}
