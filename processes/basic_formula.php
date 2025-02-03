<?php

// these functions could ultimately be integrated into a class

class PostProcess_basic_formula extends PostProcess_common
{
    public function description(): array {

        $bfdescription="Enter your formula as a symbolic expression - allows brackets and the max function <br>
        Examples : <br>
        f1+2*f2-f3/12 if you work on feeds 1,2,3 <br>
        1162.5*5.19*max(f7-f11,0) <br>
        1162.5*f10*(f7-11) <br>
        <br>
        <font color=red>Caution : (f12-f13)*(f7-f11) will not be recognized !!</font><br>
        <font color=green>check you feeds numbers before</font><br>";

        return [
            "name"=>"Basic Formula",
            "group"=>"Formula",
            "description"=>$bfdescription,
            "settings"=>[
                "formula"=>["type"=>"formula", "short"=>"Enter formula", "engine"=>5],
                "output"=>["type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name :"]
            ]
          ];
    }

    public function process($processitem): array
    {
        $result = $this->validate(processitem: $processitem);
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
        $nbf=count(value: $functions);
        

        //retrieving the formula
        $formula=$processitem->formula;
        $formula=str_replace(search: '\\',replace: '',subject: $formula);
        $formula=strtolower(string: $formula);
        $original=$formula;

        //checking the output feed
        $fopen_mode='ab';
        if ($processitem->process_mode=='all') {
            $fopen_mode='wb';
        }
        $out=$processitem->output;
        if(!$out_meta = getmeta(dir: $dir,id: $out)) return ["success"=>false, "message"=>"could not get meta for $out"];
        if (!$out_fh = @fopen(filename: "$dir$out.dat", mode: $fopen_mode)) {
            return ["success"=>false, "message"=>"could not open $dir $out.dat"];
        }

        //we catch the distinct feed numbers involved in the formula
        $feed_ids=[];
        while(preg_match(pattern: "/$Xf/", subject: $formula, matches: $b)){
            //removing the f...
            $feed_ids[]=substr(string: $b[0], offset: 1);
            $formula=str_replace(search: $b[0], replace: "", subject: $formula);
        }
        $formula=$original;

        $array= [];
        for ($i=0;$i<$nbf;$i++){
          $e=$functions[$i]["name"];
          $f=$functions[$i]["f"];
          while (preg_match(pattern: "/$f/",subject: $formula, matches: $tab)) {
              //we remove the first element of tab which is the complete full match
              //the formula matching /$Xbf/ is therefore tab[0]
              $matched=array_shift(array: $tab);
              $array[]=[
                  "scale"=>1,
                  "fun"=>$e,
                  "formula"=>$tab
              ];
              $index=count(value: $array);
              $formula=str_replace(search: $matched, replace: "func", subject: $formula);
              if (preg_match(pattern: "/($Xscale)func/", subject: $formula, matches: $c)){
                  if ($c[1]) $array[$index-1]["scale"]=$c[1];
              }
              $formula=str_replace(search: "$c[1]func", replace: "", subject: $formula);
          }
        }
        //checking if we have only a basic formula
        if (preg_match(pattern: "/^$Xbf$/", subject: $formula, matches: $tab)){
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
          return ["success"=>false, "message"=>"could not understand your formula SORRY...."];
        }

        $elements=[];
        foreach ($array as $a){
            $element=new stdClass();
            // we analyse the scaling parameter
            $fly=[];
            foreach (preg_split(pattern: "@(?=(\*|\/))@", subject: $a["scale"]) as $piece) {
              if ($result=preg_match(pattern: "/($Xop)?($Xnbr)?($Xf)?/", subject: $piece, matches: $b)){
                if (count(value: $b)>2){
                  $c=ftoa(b: $b);
                  if($c[2]) $fly[]=$c;
                }
              }
            }
            $element->scale=$fly;
            $element->function=$a["fun"];
            if (count(value: $a["formula"]) > 1) $element->arg2=$a["formula"][1];
            // we analyse the formula
            foreach(preg_split(pattern: "@(?=(-|\+))@", subject: $a["formula"][0]) as $pieces) {
              if(strlen(string: $pieces)){
                $fly=[];
                foreach(preg_split(pattern: "@(?=(\*|\/))@", subject: $pieces) as $piece) {
                  if ($result=preg_match(pattern: "/($Xop)?($Xnbr)?($Xf)?/", subject: $piece, matches: $b)) {
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
            if(!$meta = getmeta(dir: $dir, id: $id)) return array("success"=>false, "message"=>"could not get meta for $id");
            $feeds_meta[$id]=$meta;
            if (!$fh = @fopen(filename: "$dir$id.dat", mode: 'rb')) {
                return ["success"=>false, "message"=>"could not open $dir $id.dat"];
            }
            $feeds_dat[$id]=$fh;
        }

        $compute_meta= call_user_func_array(callback: "compute_meta", args: $feeds_meta);

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
            $s1=bfo(elements: [$element->scale], feeds_meta: $feeds_meta, feeds_dat: $feeds_dat, time: $time);
            $s2=bfo(elements: $element->formula, feeds_meta: $feeds_meta, feeds_dat: $feeds_dat, time: $time);
            //print($s1."-----".$s2);
            if (!is_nan(num: $s1) && !is_nan(num: $s2)) {
              if($element->function=="max") {
                $s[]=$s1*max([$s2, $element->arg2]);
              }
              if($element->function=="brackets" || $element->function=="none") {
                $s[]=$s1*$s2;
              }
            } else $s[] = NAN;
          }
          if (!in_array(needle: NAN, haystack: $s)){
            $sum=array_sum(array: $s);
          } else $sum=NAN;
          $buffer.=pack("f", $sum);
        }

        if(!$buffer) {
            return ["success"=>false, "message"=>"nothing to write - all is up to date"];
        }

        if(!$written_bytes=fwrite(stream: $out_fh, data: $buffer)){
            foreach ($feeds_dat as $f) fclose(stream: $f);
            fclose(stream: $out_fh);
            return ["success"=>false, "message"=>"unable to write to the file with id=$out"];
        }
        $nbdataswritten=$written_bytes/4;
        print("NOTICE: basic_formula() wrote $written_bytes bytes ($nbdataswritten float values) \n");
        //we update the meta only as the dat has been filled
        createmeta(dir: $dir, id: $out, meta: $out_meta);
        foreach ($feeds_dat as $f) fclose(stream: $f);
        fclose(stream: $out_fh);
        print("last time value: $time / $sum \n");
        updatetimevalue(id: $out, time: $time, value: $sum);
        return ["success"=>true, "message"=>"bytes written: $written_bytes, last time value: $time $sum"];
    }
}
