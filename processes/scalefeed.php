<?php

class PostProcess_scalefeed extends MapProcess
{
    public function description() {
        return array(
            "name"=>"Scale feed",
            "group"=>"Calibration",
            "description"=>"Multiply a feed by a constant value",
            "order"=>1,
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to scale:"),
                "scale"=>array("type"=>"value", "short"=>"Scale by:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    protected function value($value)
    {
        return $value * $this->params->scale;
    }
}
