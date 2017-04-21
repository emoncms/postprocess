<?php

function importcalc($dir,$processitem)
{
    if (!isset($processitem->generation)) return false;
    if (!isset($processitem->consumption)) return false;
    if (!isset($processitem->output)) return false;
    
    $generation = $processitem->generation;
    $consumption = $processitem->consumption;
    $output = $processitem->output;
    // --------------------------------------------------
    
    if (!file_exists($dir.$generation.".meta")) {
        print "input file $generation.meta does not exist\n";
        return false;
    }

    if (!file_exists($dir.$consumption.".meta")) {
        print "output file $consumption.meta does not exist\n";
        return false;
    }
    
    if (!file_exists($dir.$output.".meta")) {
        print "output file $output.meta does not exist\n";
        return false;
    }

    $gen_meta = getmeta($dir,$generation);
    $use_meta = getmeta($dir,$consumption);
    
    if ($gen_meta->start_time != $use_meta->start_time) {
        print "start_time of generation and consumption feeds are different\n";
        return false;
    }
    
    if ($gen_meta->interval != $use_meta->interval) {
        print "interval of generation and consumption feeds are different\n";
        return false;
    }
    
    createmeta($dir,$output,$gen_meta);
    $out_meta = getmeta($dir,$output);
    // if ($om->npoints >= $im->npoints) {
    //   print "output feed already up to date\n";
    //   return false;
    // }

    if (!$gen_fh = @fopen($dir.$generation.".dat", 'rb')) {
        echo "ERROR: could not open $dir $generation.dat\n";
        return false;
    }

    if (!$use_fh = @fopen($dir.$consumption.".dat", 'rb')) {
        echo "ERROR: could not open $dir $consumption.dat\n";
        return false;
    } 
    
    if (!$out_fh = @fopen($dir.$output.".dat", 'c+')) {
        echo "ERROR: could not open $dir $output.dat\n";
        return false;
    }
    
    // get start position
    $start_pos = $out_meta->npoints;
    
    // get end position
    $end_pos = $gen_meta->npoints;
    if ($use_meta->npoints<$end_pos) $end_pos = $use_meta->npoints;
    
    $buffer = "";
    $gen = 0;
    $use = 0;
    
    fseek($gen_fh,$start_pos*4);
    fseek($use_fh,$start_pos*4);
    fseek($out_fh,$start_pos*4);
    
    for ($n=($start_pos+1); $n<=$end_pos; $n++) {
        $gen_tmp = unpack("f",fread($gen_fh,4));
        $use_tmp = unpack("f",fread($use_fh,4));
        
        $import = NAN;
        if (!is_nan($gen_tmp[1]) && !is_nan($use_tmp[1])) 
        { 
            $gen = 1*$gen_tmp[1];
            $use = 1*$use_tmp[1];       
        
            $import = $use - $gen;
            if ($import>0) $import = 0;
        }
        $buffer .= pack("f",$import);
    }
    
    fwrite($out_fh,$buffer);
    
    print "bytes written: ".strlen($buffer)."\n";
    fclose($out_fh);
    fclose($gen_fh);
    fclose($use_fh);
    
    $time = $gen_meta->start_time + ($gen_meta->npoints * $gen_meta->interval);
    $value = $import;
    
    print "last time value: ".$time." ".$value."\n";
    updatetimevalue($output,$time,$value);
    
    return true;
}
