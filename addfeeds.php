<?php

function addfeeds($dir,$processitem)
{
    if (!isset($processitem->feedA)) return false;
    if (!isset($processitem->feedB)) return false;
    if (!isset($processitem->output)) return false;
    
    $feedA = $processitem->feedA;
    $feedB = $processitem->feedB;
    $output = $processitem->output;
    // --------------------------------------------------
    
    if (!file_exists($dir.$feedA.".meta")) {
        print "input file $feedA.meta does not exist\n";
        return false;
    }

    if (!file_exists($dir.$feedB.".meta")) {
        print "output file $feedB.meta does not exist\n";
        return false;
    }
    
    if (!file_exists($dir.$output.".meta")) {
        print "output file $output.meta does not exist\n";
        return false;
    }

    $feedA_meta = getmeta($dir,$feedA);
    $feedB_meta = getmeta($dir,$feedB);
    
    if ($feedA_meta->start_time != $feedB_meta->start_time) {
        print "start_time of feedA and feedB feeds are different\n";
        return false;
    }
    
    if ($feedA_meta->interval != $feedB_meta->interval) {
        print "interval of feedA and feedB feeds are different\n";
        return false;
    }
    
    createmeta($dir,$output,$feedA_meta);
    $out_meta = getmeta($dir,$output);
    // if ($om->npoints >= $im->npoints) {
    //   print "output feed already up to date\n";
    //   return false;
    // }

    if (!$feedA_fh = @fopen($dir.$feedA.".dat", 'rb')) {
        echo "ERROR: could not open $dir $feedA.dat\n";
        return false;
    }

    if (!$feedB_fh = @fopen($dir.$feedB.".dat", 'rb')) {
        echo "ERROR: could not open $dir $feedB.dat\n";
        return false;
    } 
    
    if (!$out_fh = @fopen($dir.$output.".dat", 'c+')) {
        echo "ERROR: could not open $dir $output.dat\n";
        return false;
    }
    
    // get start position
    $start_pos = $out_meta->npoints;
    
    // get end position
    $end_pos = $feedA_meta->npoints;
    if ($feedB_meta->npoints<$end_pos) $end_pos = $feedB_meta->npoints;
    
    $buffer = "";
    $A = 0;
    $B = 0;
    
    fseek($feedA_fh,$start_pos*4);
    fseek($feedB_fh,$start_pos*4);
    fseek($out_fh,$start_pos*4);
    
    for ($n=($start_pos+1); $n<=$end_pos; $n++) {
        $feedA_tmp = unpack("f",fread($feedA_fh,4));
        $feedB_tmp = unpack("f",fread($feedB_fh,4));
        
        $sum = NAN;
        if (!is_nan($feedA_tmp[1]) && !is_nan($feedB_tmp[1])) 
        { 
            $A = 1*$feedA_tmp[1];
            $B = 1*$feedB_tmp[1];
            $sum = $A + $B;
        }
        $buffer .= pack("f",$sum);
    }
    
    fwrite($out_fh,$buffer);
    
    print "bytes written: ".strlen($buffer)."\n";
    fclose($out_fh);
    fclose($feedA_fh);
    fclose($feedB_fh);
    
    $time = $feedA_meta->start_time + ($feedA_meta->npoints * $feedA_meta->interval);
    $value = $sum;
    
    print "last time value: ".$time." ".$value."\n";
    updatetimevalue($output,$time,$value);
    
    return true;
}
