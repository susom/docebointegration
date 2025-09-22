<?php
namespace Stanford\DoceboIntegration;

/** @var \Stanford\DoceboIntegration\DoceboIntegration $module */

try{
    $result = $module->getDoceboClient()->get('/learn/v1/courses');
    echo '<pre>';
    print_r($result);
    echo '</pre>';
}catch (\Exception $e){
    echo "Error: " . $e->getMessage();
    exit;
}


