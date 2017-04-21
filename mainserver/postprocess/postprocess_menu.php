<?php

    $domain = "messages";
    bindtextdomain($domain, "Modules/feed/locale");
    bind_textdomain_codeset($domain, 'UTF-8');

    $menu_dropdown_config[] = array('name'=> dgettext($domain, "Post Process"), 'icon'=>'icon-repeat', 'path'=>"postprocess" , 'session'=>"write", 'order' => 30 );
