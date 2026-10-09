<?php

class PostProcess_importcalc extends CombineProcess
{
    protected $inputs = array("use", "gen");
    protected $output = "out";

    public function description() {
        return array(
            "name"=>"Import calculation",
            "group"=>"Solar",
            "description"=>"Calculate grid import from consumption and generation",
            "settings"=>array(
                "use"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
                "gen"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
                "out"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter import feed name:", "nameappend"=>"")
            )
        );
    }

    // Missing generation is treated as no generation
    protected function row($use, $gen)
    {
        if (is_nan($use)) return NAN;
        if (!is_nan($gen)) $use -= $gen;
        return $use < 0 ? 0 : $use;
    }
}
