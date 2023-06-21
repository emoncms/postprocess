<?php

$schema['postprocess'] = array(
    'processid' => array('type' => 'int(11)', 'Null'=>false, 'Key'=>'PRI', 'Extra'=>'auto_increment'),
    'userid' => array('type' => 'int(11)'),
    'status' => array('type' => 'varchar(255)'),
    'status_updated' => array('type' => 'int(11)'),
    'status_message' => array('type' => 'varchar(255)'),
    'params' => array('type' => 'text')
);
