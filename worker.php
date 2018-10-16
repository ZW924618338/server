<?php
require_once 'connection.php';
require_once 'timer.php';
class worker
{
    private $host;
    private $port;
    private $serverSocket;
    private $childPid = array();
    public  $childNum = 1;
    public  $clientNum = 0;
    public  $onWorkerStart;
    public  $onConnect;
    public  $onMessage;
    public  $onClose;
    public  $workerId;
    public  $isDaemon = false;
    public  $channelServerHost;
    public  $channelServerPort;
    public  $clientSocket;
    public  $connections = array();
    public  $pidFile = "./worker.pid";
    public  $redirectStdFile = "/dev/null";
  
    public function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    private function init()
    {
        $this->listen();
        for ($i = 0; $i < $this->childNum; $i++) {
            $this->forkWorker();
        }
    }

    private function forkWorker()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('fork error');
        } elseif ($pid) {
            $this->childPid[$pid] = $pid;
        } else {
            $this->workerId = posix_getpid();
            pcntl_signal(SIGINT, SIG_DFL); //SIG_DFL  Default signal handler
            pcntl_signal(SIGTERM, SIG_DFL);
            $this->redirectStd();
            $this->creatEvent();
        }
    }

    private function creatEvent()
    {
        if(!empty($this->channelServerHost) && !empty($this->channelServerPort)){
            $client = @stream_socket_client("tcp://" . $this->channelServerHost . ":" . $this->channelServerPort, $errno, $errstr, 30);
            if(!$client){
                self::printIn("cannot connect to the channel server:$errstr ($errno)");
                exit(1);
            }
            $this->clientSocket = $client;
        }
        $eventBase = new EventBase();
        if(!empty($this->clientSocket)) {
            $connection = new connection($this->clientSocket, $eventBase, 0);
            $connection->onMessage = $this->onMessage;
            $connection->isWsClient = 0;
        }
        timer::init($eventBase);
        if ($this->onWorkerStart) {
            call_user_func($this->onWorkerStart, $this);
        }
        $event = new Event($eventBase, $this->serverSocket, Event::READ | Event::PERSIST, array($this, 'accept'), $eventBase);
        $event->add();
        $eventBase->loop();
        exit;
    }

    public function accept($serverSocket, $flag, $eventBase)
    {
        $clientSocket = stream_socket_accept($serverSocket);
        if($clientSocket == false) return;
        $header = fread($clientSocket, 1024);
        $this->performHandshake($header, $clientSocket, $this->host, $this->port);
        $connection = new connection($clientSocket, $eventBase);
        $this->connections[$connection->fd] = $connection;
        $this->clientNum++;
        $connection->onMessage = $this->onMessage;
        $connection->onClose = $this->onClose;
        if ($this->onConnect) {
            call_user_func($this->onConnect, $connection);
        }
    }

    public function run()
    {
        $this->parseCmd();
        $this->daemon();
        $this->init();
        $this->installSignal();
        $this->monitor();
    }

    private function monitor()
    {
        $this->savePid(); 
        while (true) {
            pcntl_signal_dispatch();
            foreach ($this->childPid as $key => $pid) {
                $res = pcntl_waitpid($pid, $status, WNOHANG);
                if ($res == -1 || $res > 0) {
                    if(pcntl_wifexited($status) && pcntl_wexitstatus($status)){ //Normal exit
                        if($this->isDaemon) self::printIn("The program failed to start. You can view the log file.");
                        exit;
                    }
                    unset($this->childPid[$key]);
                    $this->forkWorker();
                    $this->savePid();
                }
                
            }
            usleep(100000);
        }
    }

    private function daemon()
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
            self::printIn("Redirect failed");
            exit(1);
        }      
    }

    private function parseCmd()
    {
        global $argv; 
        if (!isset($argv[1]) || isset($argv[2])) {
            $this->showUsage();
            exit;
        }
        $isStart = false;
        $pidStr = is_file($this->pidFile) ? file_get_contents($this->pidFile) : '';
        if(!empty($pidStr)){
            $allPid = unserialize($pidStr);
        } 
        if(!empty($allPid) && posix_kill($allPid[0], 0)) $isStart = true;
        switch ($argv[1]){
            case "start":
            if($isStart == true){
               self::printIn("The process has started");
               exit;
            }
            break;
            case "stop":
            $sig = SIGINT;
            if($isStart == false){
                self::printIn("The process did not start");
                exit;
            }
            posix_kill($allPid[0], $sig);
            exit;
            case "restart":
            if($isStart == false){
                self::printIn("The process did not start");
                exit;
            }
            $sig = SIGTERM;
            posix_kill($allPid[0], $sig);
            usleep(100000);
            if(posix_kill($allPid[0], 0)){
                exit('Restart fail...');
            }
            break;
            default:
            $this->showUsage();
            exit;
        }
    }


    private function savePid()
    {
        $pid = posix_getpid();
        $pidStr = serialize([$pid, $this->childPid]);
        if (file_put_contents($this->pidFile, $pidStr) == false){
            foreach ($this->childPid as $key => $pid) {
                 posix_kill($pid , SIGINT);
            }
            self::printIn("Cannot save pid file");
            exit;
        }
    }

    private function installSignal()
    {
        pcntl_signal(SIGINT,  array($this, 'sigHandler'));
        pcntl_signal(SIGTERM, array($this, 'sigHandler'));
    }


    private  function listen()
    {
        $context_option['socket']['so_reuseport'] = 1;
        $context = stream_context_create($context_option);
        $this->serverSocket = stream_socket_server("tcp://" . $this->host . ":" . $this->port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        stream_set_blocking($this->serverSocket, 0);
    }
    
    private  function stop($sig)
    {
       $pidStr = is_file($this->pidFile) ? file_get_contents($this->pidFile) : '';
       if(!empty($pidStr)){
            $allPid = unserialize($pidStr);
       }
       if(!empty($allPid) && !is_array($allPid)){
          self::printIn("Pid file is empty");
          exit;
       }
       foreach ($allPid[1] as $key => $pid) {
              posix_kill($pid, $sig);
       }
       exit;
    }


    private  function showUsage()
    {

       self::printIn("Usage:");
       self::printIn("php your_script_file start");
       self::printIn("php your_script_file stop");
       self::printIn("php your_script_file restart");
       
    }

    public function sigHandler($signal)
    {
         switch ($signal) {
             case SIGINT:
                 $this->stop($signal);
                 break;
            case SIGTERM:
                 $this->stop($signal);
                 break;    
         }
    
    }

    private function performHandshake($recevedHeader, $client, $host, $port)
    {
        $headers = array();
        $lines = preg_split("/\r\n/", $recevedHeader);
        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }
        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Origin: {$headers['Host']}\r\n" .
            "Sec-WebSocket-Location: ws://{$headers['Host']}\r\n" .
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        fwrite($client, $upgrade, strlen($upgrade));
    }

    public static function printIn($msg='')
    {
          echo $msg."\n";
    }

}
