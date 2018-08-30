<?php

class channel
{
    public $serverSocket;
    public $connections = array();
    public $buffers = array();
    static $fd = 0;
    public $isDaemon = true;
    public $redirectStdFile = "./channel.log";

    public function __construct($host, $port)
    {
        $this->serverSocket = stream_socket_server("tcp://" . $host . ":" . $port, $errno, $errstr);
        stream_set_blocking($this->serverSocket, 0);
        $this->run();
        
    }

    public function accept($serverSocket, $flag, $eventBase)
    {
        $clientSocket = stream_socket_accept($serverSocket);
        stream_set_blocking($clientSocket, 0);
        self::$fd++;
        $bufferEvent = new EventBufferEvent($eventBase, $clientSocket, 0, array($this, 'read'), null, array($this, 'error'), self::$fd);
        $bufferEvent->enable(Event::READ | Event::PERSIST);
        $this->connections[self::$fd] = $clientSocket;
        $this->buffers[self::$fd] = $bufferEvent;
        echo "client".self::$fd."connection\n";
    }

    public function read($bufferEvent, $fd)
    {
        $data = @$bufferEvent->read(1024);
        if ($data != '') {
            foreach($this->buffers as $fd=>$buffer){
                $this->send($fd,$data);
            }
        }
    }

    public function error($bufferEvent, $error, $fd)
    {
        $bufferEvent->disable(Event::READ | Event::WRITE);
        $bufferEvent->free();
        echo "client".$fd."close\n";
        unset($this->connections[$fd], $this->buffers[$fd]);
    }

    public function send($fd,$data)
    {
        $this->buffers[$fd]->write($data);
    }

    public  function run()
    {
        $this->daemon();
        $this->redirectStd();
        $eventBase = new EventBase();
        $event = new Event($eventBase, $this->serverSocket, Event::READ | Event::PERSIST, array($this, 'accept'), $eventBase);
        $event->add();
        $eventBase->loop();
    }

     public function daemon()
     {
        if ($this->isDaemon == false) {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if ($pid < 0) {
            echo "Fork error\n";
            exit;
        } elseif ($pid > 0) {
            exit;
        }

        if (posix_setsid() < 0) {
            echo "Set error\n";
            exit;
        }

        $pid = pcntl_fork();
        if ($pid < 0) {
            echo "Fork error\n";
            exit;
        } elseif ($pid > 0) {
            exit;
        }
    }

    private function redirectStd()
    {
       if($this->isDaemon == false){
             return;   
        }
        global $STDIN , $STDOUT, $STDERR;
        $fp = fopen($this->redirectStdFile, 'a');
        if($fp){
            fclose($fp);
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            $STDIN =  fopen("/dev/null", 'r');
            $STDOUT = fopen($this->redirectStdFile, 'a');
            $STDERR = fopen($this->redirectStdFile, 'a');
        }else{
            echo "Redirect failed\n";
            exit;
        }      
    }

}


$channel = new channel('0.0.0.0', '9051');//Intranet IP
