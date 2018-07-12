<?php

class connection
{
    public $socket;
    public $bufferEvent;
    public $eventBase;
    public $onMessage;
    public $onClose;
    public $fd = 0;
    static $clientNum = 0;
    public $isWsClient = 1;

    public function __construct($clientSocket, $eventBase, $isWsClient = 1)
    {
        $this->socket = $clientSocket;
        $this->eventBase = $eventBase;
        stream_set_blocking($this->socket, 0);
        if ($isWsClient == 1) self::$clientNum++;
        $this->fd = self::$clientNum;
        $this->listen();
    }

    public function listen()
    {
        $bufferEvent = new EventBufferEvent($this->eventBase, $this->socket, 0, array($this, 'read'), null, array($this, 'error'), $this->fd);
        $bufferEvent->enable(Event::READ | Event::PERSIST);
        $this->bufferEvent = $bufferEvent;
    }

    public function read($bufferEvent, $fd)
    {
        $buffer = $bufferEvent->read(1024);
        $data = $this->isWsClient ? $this->unmask($buffer) : $buffer;
        if (!empty($data) && $this->onMessage) {
            call_user_func($this->onMessage, $this, $data);
        }
    }

    public function error($bufferEvent, $error, $fd)
    {
        $bufferEvent->disable(Event::READ | Event::WRITE);
        $bufferEvent->free();
        if ($this->onClose) {
            call_user_func($this->onClose, $this);
        }
    }

    public function send($data)
    {
        $data = $this->mask($data);
        $this->bufferEvent->write($data);
    }

    //解码数据
    public function unmask($text)
    {
        $length = ord(substr($text, 1, 1)) & 0x7F;
        $opcode = ord(substr($text, 0, 1)) & 0x0F;
        //$opcode=8说明浏览器刷新、关闭或客户端主动关闭
        $isMask = (ord(substr($text, 1, 1)) & 0x80) >> 7;
        if ($opcode == 8 || $isMask != 1) return 0;
        if ($length == 126) {
            $masks = substr($text, 4, 4);
            $data = substr($text, 8);
        } elseif ($length == 127) {
            $masks = substr($text, 10, 4);
            $data = substr($text, 14);
        } else {
            $masks = substr($text, 2, 4);
            $data = substr($text, 6);
        }
        $text = "";
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        return $text;
    }

    //编码数据
    public function mask($text)
    {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);

        if ($length <= 125)
            $header = pack('CC', $b1, $length);
        elseif ($length > 125 && $length < 65536)
            $header = pack('CCn', $b1, 126, $length);
        elseif ($length >= 65536)
            $header = pack('CCNN', $b1, 127, $length);
        return $header . $text;
    }

    public function __destruct()
    {

    }


}