<?php

$config = [
    "device_ip" => "192.168.100.87",
    "username" => "admin",
    "password" => "Sterlings1122@"
];


function hikvisionUserSearch($employeeNo)
{
    global $config;


    $url = "https://".$config['device_ip']."/ISAPI/AccessControl/UserInfo/Search?format=json";


    $payload = [
        "UserInfoSearchCond" => [
            "searchID" => "1",
            "searchResultPosition" => 0,
            "maxResults" => 1,
            "employeeNoList" => [
                [
                    "employeeNo" => $employeeNo
                ]
            ]
        ]
    ];


    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    curl_setopt(
        $ch,
        CURLOPT_USERPWD,
        $config['username'].":".$config['password']
    );

    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));


    $response = curl_exec($ch);

    curl_close($ch);


    return json_decode($response,true);
}



$raw = file_get_contents("php://input");


$data = json_decode($raw,true);


file_put_contents(
    "/tmp/hikvision_debug.log",
    date("Y-m-d H:i:s")."\n".$raw."\n\n",
    FILE_APPEND
);



if(isset($data['AccessControllerEvent'])){


    $event = $data['AccessControllerEvent'];


    $inner = $event['AccessControllerEvent'] ?? [];


    $serial = $inner['serialNo'] ?? null;


    $time = $event['dateTime'] ?? date("Y-m-d H:i:s");


    $verify = $inner['currentVerifyMode'] ?? '';



    /*
       Temporary lookup test
    */

    if($serial){


        $user = hikvisionUserSearch($serial);


        file_put_contents(
            "/tmp/hikvision_user_lookup.log",
            print_r($user,true),
            FILE_APPEND
        );

    }

}


http_response_code(200);

echo "OK";
