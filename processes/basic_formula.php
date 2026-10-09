<?php

/*
format an array for the formula engine
$b is the result of a preg_match on a formula chunk with the regexp /($Xop)?($Xnbr)?($Xf)?/
$Xop : regexp for operator
$Xnbr : regexp for float or int value
$Xf : regexp for a feed
ouputs a formula element as a 3 elements vector :
0 -> type of data ie "feed" ou "value"
1 -> operator
2 -> value or feed number
a missing value is 1, e.g. a lone operator
*/
function ftoa($b): array
{
    $c = [];
    if (sizeof(value: $b) == 4) {
        $c[0] = "feed";
        $c[2] = intval(value: substr(string: $b[3], offset: 1));
    } else {
        $c[0] = "value";
        $c[2] = $b[2];
        if ($c[2] === "") $c[2] = "1";
    }
    $c[1] = $b[1];
    if (!$c[1]) $c[1] = '+';
    return $c;
}

function formula_to_array($formula_string, $reg_exp): array
{
  //process a formula string to an array
  $fly=[];
  foreach (preg_split(pattern: "@(?=(\*|\/))@",subject: $formula_string) as $piece) {
    if (preg_match(pattern: $reg_exp, subject: $piece, matches: $b)){
      if (count(value: $b)>2){
        $fly[]=ftoa(b: $b);
      }
    }
  }
  return $fly;
}

class PostProcess_basic_formula extends CombineProcess
{
    private $elements = [];
    private $feed_ids = [];

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

    public function process($params): array
    {
        $result = $this->validate(processitem: $params);
        if (!$result["success"]) return $result;

        if (!$parsed = $this->parse_formula(formula: $params->formula)) {
          return ["success"=>false, "message"=>"could not understand your formula SORRY...."];
        }
        $this->elements=$parsed["elements"];
        $this->feed_ids=$parsed["feed_ids"];
        return parent::process($params);
    }

    // parse a formula into elements and the feed ids it uses, false if not understood
    public function parse_formula($formula)
    {
        $DEBUG = 0;
        // regular expression to recognize a float or int value
        // use of ?: not to perturb things in creating useless references
        $Xnbr="(?:[0-9]+\.[0-9]+|[0-9]+)";
        // regexp for a feed
        $Xf="f\d+";
        // regexp for an operator
        // it is better that users dont use blank instead of * but blank is accepted....
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
          ["name"=>"max","f"=>"max\(($Xbf),($Xnbr|$Xbf)\)"],
          ["name"=>"brackets","f"=>"\(($Xbf)\)"],
        ];
        $nbf=count(value: $functions);
        

        //removing useless brackets
        $pos=0;
        $original=$formula;
        while ($pos<strlen(string: $original)) {
            $position_start = strpos(haystack: $original, needle: "(", offset: $pos);
            if ($position_start===false) break;
            //is the bracket belonging to a function ??
            $function_found=false;
            if ($position_start>=3) {
              if (substr(string: $original, offset:$position_start-3, length: 3)=="max") {
                $function_found=true;
              }
            }
            $position_end = strpos(haystack: $original, needle: ")", offset: $pos);
            $chunk=substr(string: $original, offset: $position_start+1, length: $position_end-$position_start-1);
            if (!str_contains(haystack: $chunk, needle: "+") && !str_contains(haystack: $chunk, needle: "-") && !$function_found){
              $formula=str_replace(search: "($chunk)", replace: $chunk, subject: $formula);
            }
            $pos+=$position_end+1;
        }
        //adding missing * for multiplication
        //initial implentation
        //$formula=str_replace(search: '-(',replace: '-1*(',subject: $formula);
        //$formula=str_replace(search: '+(',replace: '+1*(',subject: $formula);
        //to cover cases like +or-( and +or-2(
        //we use $Xarithmop and not $XSop because with $XSop we would replace all ( by 1*(
        while (preg_match(pattern: "/($Xarithmop){1}($Xnbr)*\(/", subject: $formula, matches: $tab)){
            $replacement=match(count(value: $tab)){
              2=>"$tab[1]1*(",
              3=>"$tab[1]$tab[2]*(",
            };
            $formula=str_replace(search: $tab[0], replace: $replacement, subject: $formula);
        };
        //to cover remaining cases like 2(
        while (preg_match(pattern: "/($Xnbr)\(/", subject: $formula, matches: $tab)) {
            $formula=str_replace(search: $tab[0], replace: "$tab[1]*(", subject: $formula);
        }
        if ($DEBUG==1) {
          print $formula;
          print "\n";
        }
        $formula=str_replace(search: '\\',replace: '',subject: $formula);
        $formula=strtolower(string: $formula);
        $original=$formula;

        //we catch the distinct feed numbers involved in the formula
        preg_match_all(pattern: "/f(\d+)/", subject: $formula, matches: $b);
        $feed_ids=array_values(array_unique(array: $b[1]));
        $formula=$original;

        $array= [];
        for ($i=0;$i<$nbf;$i++){
          $e=$functions[$i]["name"];
          $f=$functions[$i]["f"];
          if ($DEBUG==1) {
            print "SEARCHING FOR $e FUNCTION";
            print "\n";
          };
          while (preg_match(pattern: "/$f/",subject: $formula, matches: $tab)) {
              //we remove the first element of tab which is the complete full match
              //the formula matching /$Xbf/ is therefore tab[0]
              $matched=array_shift(array: $tab);
              $array[]=[
                  "scale"=>1,
                  "scale_right"=>1,
                  "fun"=>$e,
                  "formula"=>$tab
              ];
              $index=count(value: $array);
              $formula=str_replace(search: $matched, replace: "func", subject: $formula);
              if (preg_match(pattern: "/($Xscale)func/", subject: $formula, matches: $c)){
                  if ($c[1]) $array[$index-1]["scale"]=$c[1];
              }
              if (preg_match(pattern: "/func($Xscale_right)/",subject: $formula, matches: $d)){
                  if ($d[1]) $array[$index-1]["scale_right"]=$d[1];
              }
              $formula=str_replace(search: "$c[1]func$d[1]", replace: "", subject: $formula);
          }
        }
        if ($DEBUG==1) {
          print_r(value: $array);
          print "SEARCHING FOR BASIC FORMULA";
          print "\n";
        };
        //checking if we have only a basic formula
        if (preg_match(pattern: "/^$Xbf$/", subject: $formula, matches: $tab)){
            $array[]=[
                "scale"=>1,
                "scale_right"=>1,
                "fun"=>"none",
                "formula"=>$tab
            ];
            $formula=str_replace(search: $tab[0], replace: "", subject: $formula);
        }
        if ($DEBUG==1) {
          print_r(value: $array);
          if ($formula) {
            print "WHAT REMAINS IS $formula";
            print "\n";
            print "SEARCHING FOR REMAINING ADDITIONS/SUBTRACTIONS";
          } else {
            print "EVERYTHING HAS BEEN ANALYZED";
          }
          print "\n";
        }
        // searching for remaining numbers to add or subtract
        if (isset($array) and $formula) {
          while (preg_match(pattern: "/(?:$XSop)$Xnbr/", subject: $formula, matches: $tab)){
            $array[]=[
                "scale"=>1,
                "scale_right"=>1,
                "fun"=>"none",
                "formula"=>$tab
            ];
            $formula=str_replace(search: $tab[0], replace: "", subject: $formula);
          }
        }
        if ($DEBUG==1) print_r(value: $array);
        //can we decompose the formula without missing any part ?
        $original_copy=$original;
        foreach ($array as $a){
            $scale = ($a["scale"]=="1") ? "" : $a["scale"];
            $scale_right = ($a["scale_right"]=="1") ? "" : $a["scale_right"];
            $chunk = match($a["fun"]) {
              "max" => "{$scale}max({$a["formula"][0]},{$a["formula"][1]}){$scale_right}",
              "brackets" => "{$scale}({$a["formula"][0]}){$scale_right}",
              "none" => $chunk=$a['formula'][0]
            };
            $original_copy=str_replace(search: $chunk, replace: "", subject: $original_copy);
        }
        if ($DEBUG==1) {
          print $original_copy;
          print "\n\n";
        }
        if ($original_copy!="") return false;

        $elements=[];
        foreach ($array as $a){
            $element=new stdClass();
            $reg_exp = "/($Xop)?($Xnbr)?($Xf)?/";
            // we analyse the scaling parameter
            $element->scale=formula_to_array(
              formula_string: $a["scale"],
              reg_exp: $reg_exp
            );
            $element->scale_right=formula_to_array(
              formula_string: $a["scale_right"],
              reg_exp: $reg_exp
            );
            $element->function=$a["fun"];
            // arg2 IS THE SECOND ARGUMENT FOR THE MAX FUNCTION !!!
            if (count(value: $a["formula"]) > 1) {
              if (is_numeric(value: $a["formula"][1])) {
                $element->arg2 = $a["formula"][1];
              } else {
                foreach(preg_split(pattern: "@(?=(-|\+))@", subject: $a["formula"][1]) as $pieces) {
                  if (strlen(string: $pieces)) {
                    $element->arg2[]=formula_to_array(
                      formula_string: $pieces,
                      reg_exp: $reg_exp
                    );
                  }
                }
              }
            }
            // we analyse the formula
            foreach(preg_split(pattern: "@(?=(-|\+))@", subject: $a["formula"][0]) as $pieces) {
              if (strlen(string: $pieces)) {
                $element->formula[]=formula_to_array(
                  formula_string: $pieces,
                  reg_exp: $reg_exp
                );
              }
            }
            $elements[]=$element;
        }
        if ($DEBUG) print_r(value: $elements);

        return ["elements"=>$elements, "feed_ids"=>$feed_ids];
    }

    protected function input_feeds($params)
    {
        $feeds=[];
        foreach ($this->feed_ids as $id) $feeds[$id]=$id;
        return $feeds;
    }

    // formula values for a block of datapoints, the formula is evaluated one operation at a time
    // over the whole block. missing values and division by zero give NAN
    protected function block($blocks)
    {
        $n=count(value: reset($blocks));
        $total=array_fill(0,$n,0);
        foreach ($this->elements as $element) {
          $s1=$this->sum_block(terms: [$element->scale], blocks: $blocks, n: $n);
          $s2=$this->sum_block(terms: $element->formula, blocks: $blocks, n: $n);
          $s3=$this->sum_block(terms: [$element->scale_right], blocks: $blocks, n: $n);
          if ($element->function=="max") {
            $arg2_constant=is_numeric(value: $element->arg2);
            $arg2=$arg2_constant ? null : $this->sum_block(terms: $element->arg2, blocks: $blocks, n: $n);
            for ($i=0;$i<$n;$i++) {
              $a2=$arg2_constant ? $element->arg2 : $arg2[$i];
              if (is_nan($s1[$i]) || is_nan($s2[$i]) || is_nan($s3[$i]) || is_nan($a2)) $total[$i]=NAN;
              else $total[$i]+=$s1[$i]*max($s2[$i],$a2)*$s3[$i];
            }
          } else {
            for ($i=0;$i<$n;$i++) $total[$i]+=$s1[$i]*$s2[$i]*$s3[$i];
          }
        }
        return $total;
    }

    // for each datapoint of the block, the sum of the products of each term
    // a term is a list of factors [type "feed" or "value", operator, feed id or value]
    private function sum_block($terms,$blocks,$n)
    {
        $sum=array_fill(0,$n,0);
        foreach ($terms as $term) {
          $product=array_fill(0,$n,1);
          foreach ($term as $e) {
            if ($e[0]=="value") {
              $value=(float) $e[2];
              if ($e[1]=="/") $value=($value==0) ? NAN : 1/$value;
              if ($e[1]=="-") $value=-$value;
              for ($i=0;$i<$n;$i++) $product[$i]*=$value;
            } else {
              $values=$blocks[$e[2]];
              if ($e[1]=="/") {
                foreach ($values as $i=>$value) $product[$i]*=($value==0) ? NAN : 1/$value;
              } else if ($e[1]=="-") {
                foreach ($values as $i=>$value) $product[$i]*=-$value;
              } else {
                foreach ($values as $i=>$value) $product[$i]*=$value;
              }
            }
          }
          for ($i=0;$i<$n;$i++) $sum[$i]+=$product[$i];
        }
        return $sum;
    }
}
