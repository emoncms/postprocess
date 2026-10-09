<?php

class PostProcess_offsetfeed extends MapProcess
{
    public function description() {
        return array(
            "name"=>"Offset feed",
            "group"=>"Calibration",
            "description"=>"Offset a feed by a constant value",
            "order"=>2,
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to apply offset:"),
                "offset"=>array("type"=>"value", "short"=>"Offset by:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    protected function value($value)
    {
        return $value + $this->params->offset;
    }
}
