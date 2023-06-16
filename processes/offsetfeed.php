<?php

class PostProcess_offsetfeed
{
    private $dir;

    public function __construct($dir) 
    {
        $this->dir = $dir;
    }

    public function description() {
        return array(
            "name"=>"offsetfeed",
            "description"=>"Offset a feed by a constant value",
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to apply offset:"),
                "offset"=>array("type"=>"value", "short"=>"Offset by:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    public function process($processitem)
    {
        $dir = $this->dir;

        if (!isset($processitem->input)) return false;
        if (!isset($processitem->offset)) return false;
        if (!isset($processitem->output)) return false;
        
        $input = $processitem->input;
        $offset = $processitem->offset;
        $output = $processitem->output;
        // --------------------------------------------------
        
        if (!file_exists($dir.$input.".meta")) {
            print "input file $input.meta does not exist\n";
            return false;
        }
        
        if (!file_exists($dir.$output.".meta")) {
            print "output file $output.meta does not exist\n";
            return false;
        }

        $input_meta = getmeta($dir,$input);
        
        createmeta($dir,$output,$input_meta);
        $output_meta = getmeta($dir,$output);
        // if ($om->npoints >= $im->npoints) {
        //   print "output feed already up to date\n";
        //   return false;
        // }

        if (!$input_fh = @fopen($dir.$input.".dat", 'rb')) {
            echo "ERROR: could not open $dir $input.dat\n";
            return false;
        }
        
        if (!$output_fh = @fopen($dir.$output.".dat", 'c+')) {
            echo "ERROR: could not open $dir $output.dat\n";
            return false;
        }
        
        // get start position
        $start_pos = $output_meta->npoints;
        
        // get end position
        $end_pos = $input_meta->npoints;
        
        $buffer = "";
        $A = 0;
        $B = 0;
        
        fseek($input_fh,$start_pos*4);
        fseek($output_fh,$start_pos*4);
        
        for ($n=($start_pos+1); $n<=$end_pos; $n++) {
            $input_tmp = unpack("f",fread($input_fh,4));
            
            $value = 1*$input_tmp[1];
            if (!is_nan($input_tmp[1])) { 
                $value = $value + $offset;
            }
            $buffer .= pack("f",$value);
        }
        
        fwrite($output_fh,$buffer);
        
        $byteswritten = strlen($buffer);
        print "bytes written: ".$byteswritten."\n";
        fclose($output_fh);
        fclose($input_fh);
        
        $time = $input_meta->start_time + ($input_meta->npoints * $input_meta->interval);
        
        if ($byteswritten>0) {
            print "last time value: ".$time." ".$value."\n";
            updatetimevalue($output,$time,$value);
        }
        return true;
    }
}