<?php

require_once "worker.php";

$server = new worker('0.0.0.0', '9090');

$server->childNum = 2;

$server->isDaemon = false;

$server->redirectStdFile = './redirectStd.log';

// $server->channelServerHost = "0.0.0.0";

// $server->channelServerPort = "9051";

$server->onWorkerStart = function ($worker) { //$worker=$server
    echo "startup process: ".$worker->workerId."\n";
    // timer::addTimer(2, function()use($worker){
    //      foreach($worker->connections as $connection){
    //         $connection->send('current process id: '.$worker->workerId);
    //      }
    // });
    
    // timer::addTimer(2, function($fd,$what,$tid)use($worker){
    //      var_dump('timer id......'.$tid);
    //      timer::delTimer($tid);
    // });

    // $tid=timer::addTimer(2.5, function()use($worker,&$tid){
    //     var_dump('timer id......'.$tid);
    //     timer::delTimer($tid);
    // });
};

$server->onConnect = function ($connection) {
    echo "client" . $connection->fd . "connection\n";
};

$server->onMessage = function ($connection, $data)use($server){
    // isWsClient ? 
    if(!$connection->isWsClient){ 
        var_dump('channel server-'.$data);
    }else{
        //fwrite($server->clientSocket, 'hello');//must be connected to the channel server
        $connection->send($data);
        //Broadcast message
        /*foreach($server->connections as $connection){
            $connection->send($data);
        }*/
    }
};

$server->onClose = function ($connection)use($server){
    echo "client" . $connection->fd . "close\n";
    fclose($connection->socket);
    //Release resources
    $server->clientNum--;
    unset($server->connections[$connection->fd]);
    unset($connection);
};

$server->run();
