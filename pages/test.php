<?php

namespace Stanford\DoceboIntegration;

/** @var \Stanford\DoceboIntegration\DoceboIntegration $module */

try{
    $email = $module->getUser()->getEmail();
    $result = $module->getDoceboClient()->get("/manage/v1/user?search_text=$email");
    echo '<pre>';
    print_r($result['json']['data']['items'][0]);
    print_r($result['json']['data']['count']);
    echo '</pre>';
}catch (\Exception $e){
    echo "Error: " . $e->getMessage();
    exit;
}
