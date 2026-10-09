<?php

class PostProcess_solardirectcalc extends CombineProcess
{
    protected $inputs = array("use", "gen");
    protected $output = "out";

    public function description() {
        return array(
            "name"=>"Solar direct calculation",
            "group"=>"Solar",
            "description"=>"Calculate direct use of solar from consumption and generation feeds",
            "settings"=>array(
                "use"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
                "gen"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
                "out"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter solar direct feed name:", "nameappend"=>"")
            )
        );
    }

    protected function row($use, $gen)
    {
        if (is_nan($use) || is_nan($gen)) return NAN;
        return min($use, $gen);
    }
}
