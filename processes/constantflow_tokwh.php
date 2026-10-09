<?php

class PostProcess_constantflow_tokwh extends CombineProcess
{
    protected $inputs = array("tint", "text");
    private $kwh;

    public function description() {
        return array(
            "name"=>"Constant flow to kWh",
            "group"=>"Misc",
            "description"=>"Convert constant flow to kwh",
            "settings"=>array(
                "vhc"=>array("type"=>"value", "short"=>"volumetric heat capacity in Wh/m3/K"),
                "flow"=>array("type"=>"value", "short"=>"constant flow in m3/h"),
                "tint"=>array("type"=>"feed", "engine"=>5, "short"=>"Internal temperature feed / start temperature feed :"),
                "text"=>array("type"=>"feed", "engine"=>5, "short"=>"External temperature feed / return temperature feed :"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output energy feed name (kWh) :")
            )
        );
    }

    protected function reset($previous)
    {
        $this->kwh = is_nan($previous) ? 0 : $previous;
    }

    // Missing temperatures add no energy
    protected function row($tint, $text)
    {
        if (!is_nan($tint) && !is_nan($text)) {
            $this->kwh += 0.001 * $this->params->vhc * $this->params->flow * max($tint - $text, 0) * $this->interval / 3600;
        }
        return $this->kwh;
    }
}
