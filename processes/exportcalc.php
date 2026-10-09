<?php

class PostProcess_exportcalc extends CombineProcess
{
    protected $inputs = array("generation", "consumption");

    public function description() {
        return array(
            "name"=>"Export calculation",
            "group"=>"Solar",
            "description"=>"Calculate grid export from consumption and generation",
            "settings"=>array(
                "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
                "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter export feed name:", "nameappend"=>"")
            )
        );
    }

    // Missing consumption is treated as no consumption
    protected function row($gen, $use)
    {
        if (is_nan($gen)) return NAN;
        if (!is_nan($use)) $gen -= $use;
        return $gen < 0 ? 0 : $gen;
    }
}
