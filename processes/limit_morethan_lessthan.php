<?php

class PostProcess_limit_morethan_lessthan extends InPlaceProcess
{
    public function description() {
        return array(
            "name"=>"Limit more than/less than",
            "group"=>"Limits",
            "description"=>"Limits values more than or less than a certain value",
            "settings"=>array(
                "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to limit values:"),
                "morethan"=>array("type"=>"value", "short"=>"Limit values above this limit:"),
                "lessthan"=>array("type"=>"value", "short"=>"Limit values below this limit:")
            )
        );
    }

    protected function value($value)
    {
        if ($value > $this->params->morethan) return (float) $this->params->morethan;
        if ($value < $this->params->lessthan) return (float) $this->params->lessthan;
        return $value;
    }
}
