<?php

function getmeta($dir,$id) 
{
    if (!file_exists($dir.$id.".meta")) {
        print "input file $id.meta does not exist\n";
        return false;
    }
    
    $meta = new stdClass();
    $metafile = fopen($dir.$id.".meta", 'rb');
    fseek($metafile,8);
    $tmp = unpack("I",fread($metafile,4)); 
    $meta->interval = $tmp[1];
    $tmp = unpack("I",fread($metafile,4)); 
    $meta->start_time = $tmp[1];
    fclose($metafile);
    
    clearstatcache($dir.$id.".dat");
    $npoints = floor(filesize($dir.$id.".dat") / 4.0);
    $meta->npoints = $npoints;
    
    return $meta;
}

function createmeta($dir,$id,$meta) 
{
    $metafile = fopen($dir.$id.".meta", 'wb');
    fwrite($metafile,pack("I",0));
    fwrite($metafile,pack("I",0)); 
    fwrite($metafile,pack("I",$meta->interval));
    fwrite($metafile,pack("I",$meta->start_time)); 
    fclose($metafile);
}

//compute meta datas of different feeds intended for a preprocessing
function compute_meta()
{
    $numargs = func_num_args();
    $arg_list = func_get_args();
    $all_intervals=[];
    $all_start_times=[];
    $all_ending_times=[];
    $meta=new stdClass();
    for ($i = 0; $i < $numargs; $i++) {
        $all_intervals[]=$arg_list[$i]->interval;
        $all_start_times[]=$arg_list[$i]->start_time;
        $all_ending_times[]=$arg_list[$i]->start_time+$arg_list[$i]->npoints*$arg_list[$i]->interval;
    }
    $meta->interval=max($all_intervals);
    $meta->start_time=floor(max($all_start_times)/$meta->interval)*$meta->interval;
    $meta->writing_end_time=min($all_ending_times);
    //print("intervals.....");print_r($all_intervals);
    //print("start_times....");print_r($all_start_times);
    //print("ending_times.....");print_r($all_ending_times);
    print("NOTICE : output interval=$meta->interval, start=$meta->start_time, end=$meta->writing_end_time \n");
    return $meta; 
}