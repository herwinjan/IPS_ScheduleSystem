<?php
//


if (@constant('IPS_BASE') == null) { //Nur wenn Konstanten noch nicht bekannt sind.
    define('IPS_BASE', 10000);                             //Base Message
    define('IPS_DATAMESSAGE', IPS_BASE + 1100);             //Data Handler Message
    define('DM_CONNECT', IPS_DATAMESSAGE + 1);             //On Instance Connect
    define('DM_DISCONNECT', IPS_DATAMESSAGE + 2);          //On Instance Disconnect
    define('IPS_INSTANCEMESSAGE', IPS_BASE + 500);         //Instance Manager Message
    define('IM_CHANGESTATUS', IPS_INSTANCEMESSAGE + 5);    //Status was Changed
    define('IM_CHANGESETTINGS', IPS_INSTANCEMESSAGE + 6);  //Settings were Changed
    define('IPS_VARIABLEMESSAGE', IPS_BASE + 600);              //Variable Manager Message
    define('VM_CREATE', IPS_VARIABLEMESSAGE + 1);               //Variable Created
    define('VM_DELETE', IPS_VARIABLEMESSAGE + 2);               //Variable Deleted
    define('VM_UPDATE', IPS_VARIABLEMESSAGE + 3);               //On Variable Update
    define('VM_CHANGEPROFILENAME', IPS_VARIABLEMESSAGE + 4);    //On Profile Name Change
    define('VM_CHANGEPROFILEACTION', IPS_VARIABLEMESSAGE + 5);  //On Profile Action Change
}


/**
 * WebsocketClient Klasse implementiert das Websocket Protokoll als HTTP-Client
 * Erweitert IPSModule.
 *
 * @package VisonicGateway
 * @property int $Parent
 */

Class BuienradarClass
{
    public $latitude=51.919;
    public $longitude=4.266;



    private $regendata=array();

    public function __construct() {

        $this->getBuienradarData();
    }

    public function getBuienradarData()
    {
        $url="http://gadgets.buienradar.nl/data/raintext?lat=".$this->latitude."&lon=".$this->longitude;
        //print($url);
        $data=@file_get_contents($url);
        if ($data)
        {
            //print_r($data);
            $rows=explode("\n",$data);
            $regen=array();
            foreach($rows as $r)
            {
                $dd=explode("|",$r);
                if (strlen($dd[0])>0)
                {
                    $amount=round(pow(10, ($dd[0] - 109) / 32) ,2);

                    //print(date("H:i",strtotime($dd[1]))."=".$amount."|");
                    $regen[strtotime($dd[1])]=$amount;
                }
            }
            $this->regendata=$regen;
        }
        return false;
    }

    public function getWolkenData()
    {
        $url="http://api.openweathermap.org/data/2.5/weather?id=2751285&units=metric&appid=e0b832ae5bb9e8922c3fbd5f8477a1a1";

        //print($url);
        $da=@file_get_contents($url);
        if ($da)
        {
            $data=json_decode($da,true);

            return $data["clouds"]["all"];
            //print_r($data);


        }
        return false;
    }

    public function nowRain()
    {
        if (count($this->regendata)>0)
        {
            reset($this->regendata);
            if (current($this->regendata)>0 || next($this->regendata)>0)
                return true;
        }
        return false;
    }
    public function nowRainRate()
    {
        if (count($this->regendata)>0)
        {
            reset($this->regendata);
            $r1=current($this->regendata);
            $r2=next($this->regendata);
            return round(($r1+$r2)/2,1);
        }
        return 0;
    }
    public function nextHourRainRate()
    {
        if (count($this->regendata)>0)
        {
            reset($this->regendata);
            $sum=0;
            for ($t=0; $t<12; $t++)
            {
                $sum+=current($this->regendata);
                next($this->regendata);
            }
            return round($sum/12,1);
        }
        return 0;
    }
};


class ScheduleSystem extends IPSModule
{
    private $scriptConfig = "";
    private $actions=array();
    private $devices=array();
    private $scheduler=array();


    // The constructor of the module
    // Overrides the default constructor of IPS
    public function __construct($InstanceID)
    {
        // Do not delete this row
        parent::__construct($InstanceID);


        // Self-service code
    }

    // Overrides the internal IPS_Create ($ id) function
    public function Create()
    {
        // Do not delete this row.
        parent::Create();

        $this->RegisterPropertyString("ConfigScripts", "");

        $this->loadScriptConfig();

        $this->RegisterTimer ( "UpdateEvent" ,  60*1000 ,  'SCHEDULER_updateEvent($_IPS[\'TARGET\']);');

        IPS_LogMessage("SCHEDULER DEBUG", "Create!");

        return true;
    }

        // Overrides the intere IPS_ApplyChanges ($ id) function
    public function ApplyChanges()
    {
        // Do not delete this line
        IPS_LogMessage("SCHEDULER DEBUG", "Apply changes!");
        $this->loadScriptConfig();

    }

    private function loadScriptConfig()
    {
        $this->scriptConfig = $this->ReadPropertyString("ConfigScripts");


        if (strlen($this->scriptConfig) > 0) {

            $s = IPS_GetScript($this->scriptConfig);
            include("/var/lib/symcon/scripts/".$s['ScriptFile']);

            $this->actions=$actions;
            $this->scheduler=$scheduler;
            $this->devices=$devices;

           // print_r($devices);
        }
    }

    public  function SetNewValue($dev, $status)
    {
        $prev=GetValue($dev);
        if ($prev!=$status)
        {
            SetValue($dev,$status);
        }
    }
    public  function getRandom()
    {

        return RegVar_GetBuffer(16062 /*[Maassluis\Alles\Today Random]*/);
    }
    public  function getTodaySunrise()
    {
        $sunriseBasis=GetValue(10831 /*[Location\Sunrise]*/);
        return strtotime(sprintf("%02d:%02d",date("H",$sunriseBasis),date("i",$sunriseBasis)));
    }
    public  function getTodaySunset()
    {
        $sunsetBasis=GetValue(45900 /*[Location\Sunset]*/);
        return strtotime(sprintf("%02d:%02d",date("H",$sunsetBasis),date("i",$sunsetBasis)));
    }
    public  function SetValue($dev, $status)
    {
        $ret=false;
        $type = IPS_GetInstance($dev)["ModuleInfo"]["ModuleName"];
        switch($type)
        {
            case "Z-Wave Module":
                $ret=ZW_DimSet($dev,$status);
                break;
            case "Dummy Module": //Plugwise?
                IPSUtils_Include("Plugwise_Include.ips.php","IPSLibrary::app::hardware::Plugwise");
                IPSUtils_Include("Plugwise_Configuration.inc.php","IPSLibrary::config::hardware::Plugwise");
                $ident=IPS_GetObject($dev)["ObjectIdent"];

                if (isset($ident))
                {
                    $id=IPS_GetObjectIDByName("Status",$dev);
                    $prev=GetValue($id);
                    if ($prev!=$status)
                    {
                        $ret=circle_on_off($ident,$status);
                        SetValue(IPS_GetObjectIDByName("Status",$dev),$status);
                    }
                }

                break;
        }
        return $ret;
    }

    public function activateScene($scene)
    {
        foreach ($this->devices as $key=>$devices)
        {
            if ($key==$scene)
            {
                foreach ($devices as $dev)
                {
                    $this->SetNewValue($dev[0],$dev[1]);
                }
            }
        }

    }

    public function updateEvent()
    {
        IPS_LogMessage("SCHEDULER DEBUG", "check actions! #actions: ".count($this->actions));

        foreach ($this->actions as $actionamem => $actionstr)
        {
            $dec=explode(":",$actionstr);
            switch ($dec[0])
            {
                case "MOTION":
                    $m=GetValue($dec[1]);
                    if ($m>0)
                    {
                        IPS_LogMessage("SCHEDULER DEBUG", "Activate Motion Scene: $key");
                        $this->activateScene($dec[2]);
                    }

                    break;
                case "NONOTION":
                    $time=strtotime("-5 minutes");
                    $ar=AC_GetAggregatedValues ( 19483 /*[Archive]*/, $dec[1],$dec[2],$time,time(),0);
                    $move=false;
                    if (count($ar)>0)
                    {
                        foreach ($ar as $a)
                        {
                            if ($a["Max"])
                            {
                                $move=true;
                            }
                        }
                    }
                    $st=GetValue($dec[1]);
                    if ($move==false && $st==true) {
                        $this->activateScene($dec[3]);
                        IPS_LogMessage("SCHEDULER DEBUG", "deActivate Motion Scene (".$dec[2].": $key");

                    }

                    break;
            }

        }

    }


}


