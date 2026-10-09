<?php

class PostProcess_liquidorairflow_tokwh extends CombineProcess
{
    protected $inputs = array("flow", "tint", "text");
    private $kwh;

    public function description() {
        return array(
            "name"=>"Liquid or airflow to kWh",
            "group"=>"Misc",
            "description"=>"Convert liquid or airflow to kwh",
            "settings"=>array(
                "vhc"=>array("type"=>"value", "short"=>"volumetric heat capacity in Wh/m3/K"),
                "flow"=>array("type"=>"feed", "engine"=>5, "short"=>"flow in m3/h"),
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

    // Missing flow or temperatures add no energy
    protected function row($flow, $tint, $text)
    {
        if (!is_nan($flow) && !is_nan($tint) && !is_nan($text)) {
            $this->kwh += 0.001 * $this->params->vhc * $flow * max($tint - $text, 0) * $this->interval / 3600;
        }
        return $this->kwh;
    }
}
