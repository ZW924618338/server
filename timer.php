<?php

class timer
{
    static $timer;
    static $eventBase;
    static $tid = 0;

    public static function init($eventBase)
    {
       self::$eventBase=$eventBase;
         
    }

    public static function addTimer($second,$func)
    {
       self::$tid++;
       self::$timer[self::$tid] = new Event(self::$eventBase, -1, Event::TIMEOUT|Event::PERSIST, $func);
       self::$timer[self::$tid]->data = self::$tid;
       self::$timer[self::$tid]->addTimer($second);
       return self::$tid;
    }

    public static function delTimer($tid)
    {
       if((int)$tid<=0) return false;
       return self::$timer[$tid]->delTimer();
    }


}
