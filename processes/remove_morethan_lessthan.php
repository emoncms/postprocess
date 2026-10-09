<?php

class PostProcess_remove_morethan_lessthan extends InPlaceProcess
{
    public function description() {
        return array(
            "name"=>"Remove more than/less than",
            "group"=>"Limits",
            "description"=>"Remove values more than or less than a certain value",
            "settings"=>array(
                "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to remove values from:"),
                "morethan"=>array("type"=>"value", "short"=>"Remove values above this limit:"),
                "lessthan"=>array("type"=>"value", "short"=>"Remove values below this limit:")
            )
        );
    }

    protected function value($value)
    {
        if ($value > $this->params->morethan || $value < $this->params->lessthan) return NAN;
        return $value;
    }
}
