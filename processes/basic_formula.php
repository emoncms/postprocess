<?php

// these functions could ultimately be integrated into a class

class PostProcess_basic_formula extends PostProcess_common
{
    public function description() {

        $bfdescription="Enter your formula as a symbolic expression - allows brackets and the max function <br>
        Examples : <br>
        f1+2*f2-f3/12 if you work on feeds 1,2,3 <br>
        1162.5*5.19*max(f7-f11,0) <br>
        1162.5*f10*(f7-11) <br>
        <br>
        <font color=red>Caution : (f12-f13)*(f7-f11) will not be recognized !!</font><br>
        <font color=red><b>The max fonction takes 2 arguments : the first is a combination of feeds, the second can only be a number !!</b></font><br>
        <font color=green>check you feeds numbers before</font><br>";

        return array(
            "name"=>"Basic Formula",
            "group"=>"Formula",
            "description"=>$bfdescription,
            "settings"=>array(
                "formula"=>array("type"=>"formula", "short"=>"Enter formula", "engine"=>5),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name :")
            )
        );
    }

    public function process($processitem)
    {
        $DEBUG = 0;
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;

        $dir = $this->dir;
        // regular expression to recognize a float or int value
        // use of ?: not to perturb things in creating useless references
        $Xnbr="(?:[0-9]+\.[0-9]+|[0-9]+)";
        // regexp for a feed
        $Xf="f\d+";
        // regexp for an operator
        // it is better that users dont use blank instead of * but we'll try to understand those omissions....
        $Xop="(?:-|\*|\+|\/)";
        // regexp for starting a formula with an operator or with nothing
        $XSop="(?:-|\+|)";
        // regexp for an arithmetic parameter
        $Xarithmop="(?:-|\+)";
        // regexp for a basic formula, ie something like f12-f43 or 2.056*f42+f45
        $Xbf="$XSop(?:$Xnbr$Xop)*$Xf(?:$Xop$Xnbr)*(?:$Xop(?:$Xnbr$Xop)*$Xf(?:$Xop$Xnbr)*)*";
        // regexp for a scaling parameter
        $Xscaleop="(?:\*|\/)";
        $Xscale="$XSop(?:$Xnbr$Xscaleop)*(?:$Xf$Xscaleop)*";
        $Xscale_right="(?:$Xscaleop$Xnbr)*(?:$Xscaleop$Xf)*";
        // functions list
        // brackets must always be the last function in the list
        $functions=[
          ["name"=>"max","f"=>"max\(($Xbf),($Xnbr)\)"],
          ["name"=>"brackets","f"=>"\(($Xbf)\)"],
        ];
        $nbf=count($functions);

        //retrieving the formula
        $formula=$processitem->formula;
        //removing useless brackets
        $pos=0;
        $original=$formula;
        while ($pos<strlen(string: $original)) {
            $position_start = strpos(haystack: $original, needle: "(", offset: $pos);
            if ($position_start===false) break;
            $position_end = strpos(haystack: $original, needle: ")", offset: $pos);
            $chunk=substr(string: $original, offset: $position_start+1, length: $position_end-$position_start-1);
            if (!str_contains(haystack: $chunk, needle: "+") && !str_contains(haystack: $chunk, needle: "-")){
              $formula=str_replace(search: "($chunk)", replace: $chunk, subject: $formula);
            }
            $pos+=$position_end+1;
        }
        //adding missing * for multiplication
        while (preg_match("/($Xarithmop){1}($Xnbr)*\(/",$formula, $tab)){
            $replacement=match(count($tab)){
              2=>"$tab[1]1*(",
              3=>"$tab[1]$tab[2]*(",
            };
            $formula=str_replace(search: $tab[0], replace: $replacement, subject: $formula);
        };
        //$formula=str_replace(search: '-(',replace: '-1*(',subject: $formula);
        //$formula=str_replace(search: '+(',replace: '+1*(',subject: $formula);
        if ($DEBUG==1) {
          print $formula;
          print "\n";
        }
        $formula=str_replace(search: '\\',replace: '',subject: $formula);
        $formula=strtolower(string: $formula);
        $original=$formula;

        //we catch the distinct feed numbers involved in the formula
        $feed_ids=[];
        while(preg_match("/$Xf/",$formula,$b)){
            //removing the f...
            $feed_ids[]=substr($b[0],1);
            $formula=str_replace($b[0],"",$formula);
        }
        $formula=$original;

        $array= [];
        for ($i=0;$i<$nbf;$i++){
          $e=$functions[$i]["name"];
          $f=$functions[$i]["f"];
          if ($DEBUG==1) {
            print "SEARCHING FOR $e FUNCTION";
            print "\n";
          };
          while (preg_match("/$f/",$formula,$tab)) {
              //we remove the first element of tab which is the complete full match
              //the formula matching /$Xbf/ is therefore tab[0]
              $matched=array_shift($tab);
              $array[]=[
                  "scale"=>1,
                  "scale_right"=>1,
                  "fun"=>$e,
                  "formula"=>$tab
              ];
              $index=count($array);
              $formula=str_replace($matched,"func",$formula);
              if (preg_match("/($Xscale)func/",$formula,$c)){
                  if ($c[1]) $array[$index-1]["scale"]=$c[1];
              }
              if (preg_match("/func($Xscale_right)/",$formula,$d)){
                  if ($d[1]) $array[$index-1]["scale_right"]=$d[1];
              }
              $formula=str_replace("$c[1]func$d[1]","",$formula);
          }
        }
        if ($DEBUG==1) print_r($array);
        //checking if we have only a basic formula
        if ($DEBUG==1) {
          print "SEARCHING FOR BASIC FORMULA";
          print "\n";
        };
        if (preg_match("/^$Xbf$/",$formula,$tab)){
            $array[]=[
                "scale"=>1,
                "scale_right"=>1,
                "fun"=>"none",
                "formula"=>$tab
            ];
        }
        //can we rebuild the formula ?
        $original_copy=$original;
        foreach ($array as $a){
            if ($a["scale"]=="1") $scale=""; else $scale=$a["scale"];
            if ($a["scale_right"]=="1") $scale_right=""; else $scale_right=$a["scale_right"];
            if ($a["fun"]=="max"){
              $chunk="{$scale}max({$a["formula"][0]},{$a["formula"][1]})";
            }
            else if ($a["fun"]=="brackets"){
              $chunk="{$scale}({$a["formula"][0]}){$scale_right}";
            }
            else if ($a["fun"]=="none"){
              $chunk="{$scale}{$a['formula'][0]}";
            }
            $original_copy=str_replace($chunk,"", $original_copy);
        }
        if ($DEBUG==1) {
          print $original_copy;
          print "\n\n";
        }
        if ($original_copy=="") print "formula is OK!!\n"; else {
          return array("success"=>false, "message"=>"could not understand your formula SORRY....");
        }

        //checking the output feed
        $fopen_mode='ab';
        if ($processitem->process_mode=='all') {
            $fopen_mode='wb';
        }
        $out=$processitem->output;
        if(!$out_meta = getmeta($dir,$out)) return array("success"=>false, "message"=>"could not get meta for $out");
        if (!$out_fh = @fopen($dir.$out.".dat", $fopen_mode)) {
            return array("success"=>false, "message"=>"could not open $dir $out.dat");
        }

        $elements=[];
        foreach ($array as $a){
            $element=new stdClass();
            // we analyse the scaling parameter
            $fly=[];
            foreach (preg_split("@(?=(\*|\/))@",$a["scale"]) as $piece) {
              if ($result=preg_match("/($Xop)?($Xnbr)?($Xf)?/",$piece,$b)){
                if (count($b)>2){
                  $c=ftoa($b);
                  if($c[2]) $fly[]=$c;
                }
              }
            }
            $element->scale=$fly;
            $fly=[];
            foreach (preg_split("@(?=(\*|\/))@",$a["scale_right"]) as $piece) {
              if ($result=preg_match("/($Xop)?($Xnbr)?($Xf)?/",$piece,$b)){
                if (count($b)>2){
                  $c=ftoa($b);
                  if($c[2]) $fly[]=$c;
                }
              }
            }
            $element->scale_right=$fly;
            $element->function=$a["fun"];
            if (count($a["formula"]) > 1) $element->arg2=$a["formula"][1];
            // we analyse the formula
            foreach(preg_split("@(?=(-|\+))@",$a["formula"][0]) as $pieces) {
              if(strlen($pieces)){
                $fly=[];
                foreach(preg_split("@(?=(\*|\/))@",$pieces) as $piece) {
                  if ($result=preg_match("/($Xop)?($Xnbr)?($Xf)?/",$piece,$b)) {
                    $c=ftoa($b);
                    if($c[2]) $fly[]=$c;
                  }
                }
                $element->formula[]=$fly;
              }
            }
            $elements[]=$element;
        }
        if ($DEBUG) print_r($elements);

        //we retrieve the meta and open the dat files
        foreach ($feed_ids as $id){
            if(!$meta = getmeta($dir,$id)) return array("success"=>false, "message"=>"could not get meta for $id");
            $feeds_meta[$id]=$meta;
            if (!$fh = @fopen($dir.$id.".dat", 'rb')) {
                return array("success"=>false, "message"=>"could not open $dir $id.dat");
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

        if ($processitem->process_mode=='recent') {
          $writing_start_time=$out_meta->start_time+($out_meta->interval*$out_meta->npoints);
        } else {
          $writing_start_time=$out_meta->start_time;
        }
        
        $writing_end_time=$compute_meta->writing_end_time;
        $interval=$out_meta->interval;

        $buffer="";

        for ($time=$writing_start_time;$time<$writing_end_time;$time+=$interval){
          $s=[];
          foreach($elements as $element){
            $s1=bfo([$element->scale],$feeds_meta,$feeds_dat,$time);
            $s2=bfo($element->formula,$feeds_meta,$feeds_dat,$time);
            $s3=bfo([$element->scale_right],$feeds_meta,$feeds_dat,$time);
            //print("$s1-----$s2-----$s3");
            if (!is_nan($s1) && !is_nan($s2) && !is_nan($s3)) {
              if($element->function=="max") {
                $s[]=$s1*max($s2,$element->arg2)*$s3;
              }
              if($element->function=="brackets" || $element->function=="none") {
                $s[]=$s1*$s2*$s3;
              }
            } else $s[] = NAN;
          }
          if (!in_array(NAN,$s)){
            $sum=array_sum($s);
          } else $sum=NAN;
          $buffer.=pack("f",$sum);
        }

        if(!$buffer) {
            return array("success"=>false, "message"=>"nothing to write - all is up to date");
        }

        if(!$written_bytes=fwrite($out_fh,$buffer)){
            foreach ($feeds_dat as $f) fclose($f);
            fclose($out_fh);
            return array("success"=>false, "message"=>"unable to write to the file with id=$out");
        }
        $nbdataswritten=$written_bytes/4;
        print("NOTICE: basic_formula() wrote $written_bytes bytes ($nbdataswritten float values) \n");
        //we update the meta only as the dat has been filled
        createmeta($dir,$out,$out_meta);
        foreach ($feeds_dat as $f) fclose($f);
        fclose($out_fh);
        print("last time value: $time / $sum \n");
        updatetimevalue($out,$time,$sum);
        return array("success"=>true, "message"=>"bytes written: ".$written_bytes.", last time value: ".$time." ".$sum);
    }
}
