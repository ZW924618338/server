<?php

class timer
{
    static $timer;
    static $eventBase;

    public static function init($eventBase)
    {
       self::$eventBase=$eventBase;
         
    }

    public static function addTimer($second,$func)
    {
       self::$timer = new Event(self::$eventBase, -1, Event::TIMEOUT|Event::PERSIST, $func);
       self::$timer->data = self::$timer ;
       self::$timer->addTimer($second);
    }

    public static function delTimer()
    {
       self::$timer->delTimer();
    }

}
