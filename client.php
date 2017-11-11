<?php

// 上游是浏览器(uninstrument)，没有父span，但是浏览器调用client.php的server-side span可以建立

// 下面的步骤是：
// 1, 生成浏览器RPC的server-side span
// 2, 生成调用server.php的client-side span，其父span是1）中生成的span

// HTTP POST上传一批span
function postSpans(array $spans) {
    $ch = curl_init("http://localhost:9411/api/v2/spans");

    $payload = json_encode($spans);

    curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    $result = curl_exec($ch);
    curl_close($ch);
}

// 微妙时间戳
function timestamp() {
    return intval(microtime(true) * 1000 * 1000);
}

// 生成唯一ID
function idAlloc() {
    return base_convert(timestamp(), 10, 16);
}

$spans = [];

// 该rpc上游是浏览器,没有trace信息,所以生成server-side span记录本服务的处理时间
$srTimestamp = timestamp();

$span1 = [
    "traceId" => idAlloc(),
    "name" => "/method_of_a",
    "id" => idAlloc(),
    "kind" => "SERVER",
    "timestamp" => timestamp(),
    "duration" => 0,
    "debug" => true,
    "shared" => false,
    "localEndpoint" => [
        "serviceName" => "a.service.com",
        "ipv4" => "192.168.1.100",
        "port" => 80,
    ],
    "annotations" => [
        [
            "timestamp" => timestamp(), // 收到浏览器调用的时间
            "value" => "sr"
        ],

    ],
    "tags" => [
        "queryParams" => "a=1&b=2&c=3",
    ]
];

// 模拟调用b.service.com
function rpcToB($traceId, $parentSpanId) {
    // 生成rpc的spanId
    $spanId = idAlloc();

    // 假设a.service.com发起了一个rpc调用b.service.com
    // 那么它将生成client-side span
    $csTimestamp = timestamp();
    $span2 = [
        "traceId" => $traceId,
        'id' => $spanId,
        'parentId' => $parentSpanId,
        "name" => "/method_of_b",
        "kind" => "CLIENT",
        "timestamp" => timestamp(),
        "duration" => 0,
        "debug" => true,
        "shared" => false,
        "localEndpoint" => [
            "serviceName" => "a.service.com",
            "ipv4" => "192.168.1.100",
            "port" => 80,
        ],
        "annotations" => [
            [
                "timestamp" => $csTimestamp, // 发起b.service.com调用的时间
                "value" => "cs"
            ],
        ],
        "tags" => [
            "queryParams" => "e=1&f=2&g=3",
        ]
    ];

    // 在rpc请求中将traceId,parentSpanId,spanId都带给了b.service.com
    // http.call("b.service.com/method_of_b?e=1&f=2&g=3", [$traceId, $parentSpanId, $spanId])

    // 假设b.service.com收到请求后这样处理
    {
        $b_srTimestamp = timestamp();
        $span3 = [
            "traceId" => $traceId,
            'id' => $spanId,
            'parentId' => $parentSpanId,
            "name" => "/method_of_b",
            "kind" => "SERVER",
            "debug" => true,
            "shared" => true,
            "localEndpoint" => [
                "serviceName" => "b.service.com",
                "ipv4" => "192.168.1.200",
                "port" => 80,
            ],
            "annotations" => [
                [
                    "timestamp" => $b_srTimestamp, // 收到a.service.com请求的时间
                    "value" => "sr"
                ],
            ],
        ];
        // 经过200毫秒处理
        usleep(200 * 1000);
        $b_ssTimestamp = timestamp();
        $span3['annotations'][] = [
            "timestamp" => $b_ssTimestamp, // 应答a.service.com的时间
            "value" => "ss"
        ];
        postSpans([$span3]);
    }

    // a.service.com收到应答, 记录cr时间点, duration
    $crTimestamp = timestamp();
    $span2['annotations'][] = [
        "timestamp" => $crTimestamp, // 收到b.service.com应答的时间
        'value' => "cr"
    ];
    $span2['duration'] = $crTimestamp - $csTimestamp;
    global $spans;
    $spans[] = $span2;
}
rpcToB($span1['traceId'], $span1['id']);

// 模拟访问数据库
function queryDB($traceId, $parentSpanId) {
    // 生成数据库访问用的spanId
    $spanId = idAlloc();

    // 假设a.service.com查询数据库, 因为数据库无法埋点，所以只能生成client-side span
    $csTimestamp = timestamp();
    $span4 = [
        "traceId" => $traceId,
        'id' => $spanId,
        'parentId' => $parentSpanId,
        "name" => "mysql.user",
        "kind" => "CLIENT",
        "timestamp" => timestamp(),
        "duration" => 0,
        "debug" => true,
        "shared" => false,
        "localEndpoint" => [
            "serviceName" => "a.service.com",
            "ipv4" => "192.168.1.100",
            "port" => 80,
        ],
        "remoteEndpoint" => [
            "serviceName" => "mysql.service.com",
        ],
        "annotations" => [
            [
                "timestamp" => $csTimestamp, // 发起数据库查询的时间
                "value" => "cs"
            ],
        ],
        "tags" => [
            "sql" => "select * from user;",
        ]
    ];

    usleep(100 * 1000); // 模拟花费了100毫秒查询数据库

    // 得到数据库查询结果
    $crTimestamp = timestamp();
    $span4['annotations'][] = [
        "timestamp" => $crTimestamp, // 收到数据库结果的时间
        'value' => "cr"
    ];
    $span4['duration'] = $crTimestamp - $csTimestamp;
    global $spans;
    $spans[] = $span4;
}
queryDB($span1['traceId'], $span1['id']);

// a.service.com剩余代码执行花费50毫秒
usleep(50 * 1000);

//记录ss时间点, duration
$ssTimestamp = timestamp();
$span1['annotations'][] = [
    "timestamp" => $ssTimestamp, // 返回浏览器应答的时间
    'value' => "ss"
];
$span1['duration'] = $ssTimestamp - $srTimestamp; // 记录时长

$spans[] = $span1;

$ret = postSpans($spans);

?>