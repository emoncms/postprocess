<?php

function http_request($method,$url,$body)
{
    $connect_timeout = 5;
    $total_timeout = 10;
    
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    //curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    if ($body!=null) curl_setopt($curl, CURLOPT_POSTFIELDS,$body);
    
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT,$connect_timeout);
    curl_setopt($curl, CURLOPT_TIMEOUT,$total_timeout);
    
    $curl_response = curl_exec($curl);
    curl_close($curl);
    return $curl_response;
}
