<?php

require_once "worker.php";

$server = new worker('0.0.0.0', '9090');

$server->childNum = 2;

$server->isDaemon = false;

$server->redirectStdFile = './redirectStd.log';

// $server->channelServerHost = "0.0.0.0";

// $server->channelServerPort = "9051";

$server->onWorkerStart = function ($worker) { //$worker=$server
    echo "启动进程: ".$worker->workerId."\n";
    // timer::addTimer(2, function()use($worker){
    //      foreach($worker->connections as $connection){
    //         $connection->send('当前进程id: '.$worker->workerId);
    //      }
    // });
};

$server->onConnect = function ($connection) {
    echo "客户端" . $connection->fd . "连接\n";
};

$server->onMessage = function ($connection, $data)use($server){
    //判断消息是来自ws客户端还是转发服务器
    if(!$connection->isWsClient){ //isWsClient为0代表转发服务器发来的消息
        var_dump('来自转发服务器-'.$data);
    }else{
        //fwrite($server->clientSocket, '发送中文');//连接到转发服务器才可调用
        $connection->send($data);
        //广播消息
        /*foreach($server->connections as $connection){
            $connection->send($data);
        }*/
    }
};

$server->onClose = function ($connection)use($server){
    echo "客户端" . $connection->fd . "关闭\n";
    fclose($connection->socket);
    //释放资源
    $server->clientNum--;
    unset($server->connections[$connection->fd]);
    unset($connection);
};

$server->run();