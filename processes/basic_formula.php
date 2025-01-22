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
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;

        $dir = $this->dir;
        // regular expression to recognize a float or int value
        // use of ?: not to perturb things in creating useless references
        $Xnbr="(?:[0-9]+\.[0-9]+|[0-9]+)";
        // regexp for a feed
        $Xf="f\d+";
        // regexp for an operator - blank instead of * is not permitted
        $Xop="(?:-|\*|\+|\/)";
        // regexp for starting a formula with an operator or with nothing
        $XSop="(?:-|\+|)";
        // regexp for a basic formula, ie something like f12-f43 or 2.056*f42+f45
        $Xbf="$XSop(?:$Xnbr$Xop)*$Xf(?:$Xop$Xnbr)*(?:$Xop(?:$Xnbr$Xop)*$Xf(?:$Xop$Xnbr)*)*";
        // regexp for a scaling parameter
        $Xscaleop="(?:\*|\/)";
        $Xscale="$XSop(?:$Xnbr$Xscaleop)*(?:$Xf$Xscaleop)*";
        // functions list
        // brackets must always be the last function in the list
        $functions=[
          ["name"=>"max","f"=>"max\(($Xbf),($Xnbr)\)"],
          ["name"=>"brackets","f"=>"\(($Xbf)\)"],
        ];
        $nbf=count($functions);

        //retrieving the formula
        $formula=$processitem->formula;
        $formula=str_replace('\\','',$formula);
        $formula=strtolower($formula);
        $original=$formula;

        //checking the output feed
        $out=$processitem->output;
        if(!$out_meta = getmeta($dir,$out)) return array("success"=>false, "message"=>"could not get meta for $out");
        if (!$out_fh = @fopen($dir.$out.".dat", 'ab')) {
            return array("success"=>false, "message"=>"could not open $dir $out.dat");
        }

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
          while (preg_match("/$f/",$formula,$tab)) {
              //we remove the first element of tab which is the complete full match
              //the formula matching /$Xbf/ is therefore tab[0]
              $matched=array_shift($tab);
              $array[]=[
                  "scale"=>1,
                  "fun"=>$e,
                  "formula"=>$tab
              ];
              $index=count($array);
              $formula=str_replace($matched,"func",$formula);
              if (preg_match("/($Xscale)func/",$formula,$c)){
                  if ($c[1]) $array[$index-1]["scale"]=$c[1];
              }
              $formula=str_replace("$c[1]func","",$formula);
          }
        }
        //checking if we have only a basic formula
        if (preg_match("/^$Xbf$/",$formula,$tab)){
            $array[]=[
                "scale"=>1,
                "fun"=>"none",
                "formula"=>$tab
            ];
        }
        //we rebuild the formula with what we have extracted
        $recf="";
        foreach ($array as $a){
            if ($a["scale"]=="1") $scale=""; else $scale=$a["scale"];
            if ($a["fun"]=="max"){
              $recf.="{$scale}max({$a["formula"][0]},{$a["formula"][1]})";
            }
            else if ($a["fun"]=="brackets"){
              $recf.="{$scale}({$a["formula"][0]})";
            }
            else if ($a["fun"]=="none"){
              $recf.="{$scale}{$a['formula'][0]}";
            }
        }
        if ($recf==$original) print("formula is OK!!<br>"); else {
          return array("success"=>false, "message"=>"could not understand your formula SORRY....");
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
            //print($s1."-----".$s2);
            if (!is_nan($s1) && !is_nan($s2)) {
              if($element->function=="max") {
                $s[]=$s1*max($s2,$element->arg2);
              }
              if($element->function=="brackets" || $element->function=="none") {
                $s[]=$s1*$s2;
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
