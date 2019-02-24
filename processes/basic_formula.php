<?php

function basic_formula($dir,$processitem)
{
    //retrieving the formula
    $formula=$processitem->formula;
    $formula=str_replace('\\','',$formula);
    //print("$formula \n");
    
    //checking the output feed
    $out=$processitem->output;
    if(!$out_meta = getmeta($dir,$out)) return false;
    if (!$out_fh = @fopen($dir.$out.".dat", 'ab')) {
        echo "ERROR: could not open $dir $out.dat\n";
        return false;
    }
    
    $pieces=preg_split("@(?=(-|\+))@",$formula);
    //$elements > a third dimensionnal array > datas are on level 2
    //for a given level 1, we operate multiplication and division within corresponding level 2 elements and then give a sign to the result
    //the final result is the addition of the whole
    //each subarray level 2 is constructed as follow 
    //[0]=> "feed" or "value"
    //[1]=> operator +,-,* or /
    //[2]=> if feed multiplicator value (integer), if value : value number (integer)
    //[3]=> does not exist for value, if feed : feed number
    $elements=[];
    foreach($pieces as $piece){
        $temp=preg_split("@(?=(\*|\/))@",$piece);
        $fly=[];
        $i=0;
        foreach($temp as $t){
            if ($result=preg_match("/(-|\+|\*|\/)?(\d+)?(f\d+)?/",$t,$b)){
                //if we've got 4 elements, we face a feed
                //if not we face a value
                if(sizeof($b)==4) {
                    $b[3]=intval(substr($b[3],1));
                    $b[0]="feed";
                } else $b[0]="value";
                if ($i==0 && !$b[1]) $b[1]='+';
                if ($i>0 && !$b[1]) {
                    print("check your formula");
                    return false;
                }
                if (!$b[2]) $b[2]=1;
                $fly[]=$b;
                $i++;
            } else {
                print("check your formula");
                return false;
            }
        }
    $elements[]=$fly;
    }
    //print_r($elements);
    $feeds_meta=[];
    $feeds_dat=[];
    
    //we catch the distinct feed numbers involved in the formula
    $feed_ids=[];
    while(preg_match("/(f\d+)/",$formula,$b)){
        $feed_ids[]=substr($b[0],1,strlen($b[0])-1);
        $formula=str_replace($b[0],"",$formula);
    }
    
    //we retrieve the meta and open the dat files
    foreach ($feed_ids as $id){
        if(!$meta = getmeta($dir,$id)) return false;
        $feeds_meta[$id]=$meta;
        if (!$fh = @fopen($dir.$id.".dat", 'rb')) {
            echo "ERROR: could not open $dir $id.dat\n";
            return false;
        }
        $feeds_dat[$id]=$fh;
    }
    
    $compute_meta= call_user_func_array("compute_meta",$feeds_meta);
    
    //reading the output meta and if dat file is empty, we adjust interval and start_time
    //we do not report the values in the meta file at this stage. we wait for the dat file to be filled with processed datas
    //if dat file is not empty, meta file should already contain correct values
    print("NOTICE : ouput is : ($out_meta->npoints,$out_meta->interval,$out_meta->start_time) \n");
    if($out_meta->npoints==0) {
        $out_meta->interval=$compute_meta->interval;
        $out_meta->start_time=$compute_meta->start_time;
    }
    print("NOTICE : ouput is now : ($out_meta->npoints,$out_meta->interval,$out_meta->start_time) \n");
    
    $writing_start_time=$out_meta->start_time+($out_meta->interval*$out_meta->npoints);
    $writing_end_time=$compute_meta->writing_end_time;
    $interval=$out_meta->interval;
    
    $buffer="";
    
    for ($time=$writing_start_time;$time<$writing_end_time;$time+=$interval){
        $s=[];
        foreach($elements as $element){
            $values=[]; 
            foreach($element as $e){
                $value=NAN;
                if ($e[0]=="feed"){
                    $pos = floor(($time - $feeds_meta[$e[3]]->start_time) / $feeds_meta[$e[3]]->interval);
                    if ($pos>=0 && $pos<$feeds_meta[$e[3]]->npoints) {
                        fseek($feeds_dat[$e[3]],$pos*4);
                        $tmp = unpack("f",fread($feeds_dat[$e[3]],4));
                        $value = $e[2]*$tmp[1];
                    }
                }
                if ($e[0]=="value") $value = $e[2];
                if (!is_nan($value) && $value!=0){
                    if ($e[1]=="/") $value=1/$value;
                    if ($e[1]=="-") $value=-$value;
                }
                $values[]=$value;
            }
            if (!in_array(NAN,$values)){
                $s[]=array_product($values);
            } else $s[]=NAN;
        }
        if (!in_array(NAN,$s)){
            $sum=array_sum($s);
        } else $sum=NAN;
                
        //print_r("$sum \n");
        $buffer.=pack("f",$sum);
    }
    
    if(!$buffer) {
        print("WARNING: nothing to write - all is up to date \n");
        return false;
    }
    
    if(!$written_bytes=fwrite($out_fh,$buffer)){
        print("ERROR: unable to write to the file with id=$out \n");
        foreach ($feeds_dat as $f) fclose($f);
        fclose($out_fh);
        return false;
    }
    $nbdataswritten=$written_bytes/4;
    print("NOTICE: basic_formula() wrote $written_bytes bytes ($nbdataswritten float values) \n");
    //we update the meta only as the dat has been filled
    createmeta($dir,$out,$out_meta);
    foreach ($feeds_dat as $f) fclose($f);
    fclose($out_fh);
    print("last time value: $time / $sum \n");
    updatetimevalue($out,$time,$sum);
    return true;
    

}
?>