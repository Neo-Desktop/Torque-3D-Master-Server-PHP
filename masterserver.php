<?php
/***
 * MasterServer for Torque Game Engine
 **/

//------------------------------------------------------------------------------  
// Config  
//------------------------------------------------------------------------------  
$_config["port"] = 28002;
$_config["bindaddr"] = "0.0.0.0";
$_config["buflen"] = 1024;
$_config["timeout"] = 180;
$_config["enhancedfilter"] = true; //if true check query for: regionmask,bots and cpu
$_config["debugmode"] = 5; //console output: 0=disabled, 1=low, 2=recommended, 5=high
$_config["useSignals"] = true;  //unix only
$_config["useSyslog"] = false;

//------------------------------------------------------------------------------  
// Init  
//------------------------------------------------------------------------------  
set_time_limit(0); //MAXIMUM LIFETIME Unlimited
error_reporting(E_ERROR | E_PARSE);
//------------------------------------------------------------------------------  
// Helper  
//------------------------------------------------------------------------------  
function svar_dump($data, $mode)
{
    if ($mode > $_config["debugmode"]) {
        return;
    }
    ob_start();
    var_dump($data);
    $ret_val = ob_get_contents();
    ob_end_clean();
    conmsg($ret_val, $mode);
}

function conmsg($str, $mode)
{
    global $_config;

    if ($mode > $_config["debugmode"]) {
        return;
    }

    if ($_config["useSyslog"]) {
        syslog(LOG_NOTICE, $str);
    } else {
        echo($str . "n");
        flush();
        ob_flush();
    }
}

//------------------------------------------------------------------------------  
// Const  
//------------------------------------------------------------------------------  

define("Status_Dedicated", 1 << 0);
define("Status_Passworded", 1 << 1);
define("Status_Linux", 1 << 2);

//------------------------------------------------------------------------------  
// Signals and syslog  
//------------------------------------------------------------------------------  

function sig_handler($signo)
{
    conMsg("SIGNAL $signo", 2);
    switch ($signo) {
        case SIGINT:
            // interrupt same as sigterm here
        case SIGTERM:
            //  default on kill
            $GLOBALS["serverrunning"] = false;
            break;
        case SIGHUP:
            //used to flush the serverlist
            unset($GLOBALS["servers"]);
            conmsg("Server cache flushed!", 0);
            break;
        case SIGUSR1:
            //  Dump all servers
            conMsg("Serverlist >>", 0);
            foreach ($GLOBALS["servers"] as $s) {
                conMsg(
                    sPrintf(
                        "%-25s %-30s Mission:%s,Player:%s,Reg:%s,Ver:%s,Bots:%s,CPU:%s",
                        $s["IP"] . "::" . $s["PORT"],
                        $s["gametype"],
                        $s["missiontype"],
                        $s["playerCount"],
                        $s["regionMask"],
                        $s["versionNumber"],
                        $s["botCount"],
                        $s["cpuMhz"]
                    ),
                    0
                );
            }
            conMsg("<< Serverlist", 0);
            break;
        default:
            // for future use
            break;
    }
}

if ($_config["useSignals"]) {
    conMsg("Setting up Signals", 2);
    declare(ticks=1);
    pcntl_signal(SIGTERM, "sig_handler");
    pcntl_signal(SIGINT, "sig_handler");
    pcntl_signal(SIGHUP, "sig_handler");
    pcntl_signal(SIGUSR1, "sig_handler");
}

if ($_config["useSyslog"]) {
    define_syslog_variables();
    openlog("TorqueMaster", LOG_ODELAY, LOG_DAEMON);
}

//------------------------------------------------------------------------------  
// Main  
//------------------------------------------------------------------------------  
conmsg("MasterServer starting.... " . $_config["bindaddr"], "::" . $_config["port"], 1);
$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if (!socket_bind($socket, $_config["bindaddr"], $_config["port"])) {
    @socket_close($socket);
    die("Cant bind Server to $bindaddr");
};
socket_set_nonblock($socket);

conmsg("MasterServer up.", 2);
$serverrunning = true;
$servers = array();
while ($serverrunning) {
    $scnt = socket_recvfrom($socket, $buf, $_config["buflen"], 0, $clientIP, $clientPort);
    if ($scnt < 1) {
        usleep(2500);
        continue;
    }
    $clientId = $clientIP . "::" . $clientPort;
    $tmpdata = unpack("C", $buf);
    $cmdtype = $tmpdata[1];
    unset($tmpdata);
    switch ($cmdtype) {
        case 6:
            conmsg("RCV MasterServerListRequest from $clientId", 2);
            $req = array();
            $data = unpack("Ctype/CqueryFlags/VpingSessionId/Cdummy/Cgtlen", $buf);
            $needle = 8;
            $req["queryFlags"] = $data["queryFlags"];
            $req["gametype"] = substr($buf, $needle, $data["gtlen"]);
            $req["pingSessionId"] = $data["pingSessionId"];
            $needle += $data["gtlen"];
            unset($data);
            $tmpdata = unpack("C", substr($buf, $needle, 1));
            $needle++;
            $req["missiontype"] = substr($buf, $needle, $tmpdata[1]);
            $needle += $tmpdata[1];
            unset($tmpdata);
            $data = unpack(
                "CminPlayers/CmaxPlayers/VregionMask/VversionNumber/CfilterFlags/CmaxbotCount/vmincpuMhz",
                substr($buf, $needle, 14)
            );
            $needle += 14;
            //Note: rest is followed up with buddy stuff.. i ignore this here
            $req = array_merge($req, $data);
            unset($data);

            $foundServer = array();

            //hack for empty request strings:
            if ($req["missiontype"] == "") {
                $req["missiontype"] = "Any";
            }

            conmsg(
                "Query:: GameType:" . $req["gametype"] . " MissionType:" . $req["missiontype"]
                . " min/max players:" . $req["minPlayers"] . "/" . $req["minPlayers"]
                . " Region:" . $req["regionMask"]
                . " Version:" . $req["versionNumber"]
                . " MinBotcnt:" . $req["maxbotCount"]
                . " MinCPU:" . $req["mincpuMhz"]
                ,
                5
            );

            foreach (array_keys($servers) as $k) {
                if ($servers[$k]["expire"] < time()) {
                    unset($servers[$k]);
                } else {
                    if (
                        (strcasecmp($servers[$k]["gametype"], $req["gametype"]) == 0)
                        && (strcasecmp($req["missiontype"], "Any") == 0 || strcasecmp(
                                $servers[$k]["missiontype"],
                                $req["missiontype"]
                            ) == 0)
                        && $servers[$k]["playerCount"] >= $req["minPlayers"]
                        && $servers[$k]["maxPlayers"] <= $req["maxPlayers"]
                        && ($req["versionNumber"] == 0 || strcasecmp(
                                $servers[$k]["versionNumber"],
                                $req["versionNumber"]
                            ) == 0)
                        && (!$_config["enhancedfilter"]
                            || (
                                ($req["regionMask"] == 0 || $servers[$k]["regionMask"] == $req["regionMask"])
                                && $servers[$k]["botCount"] <= $req["maxbotCount"]
                                && $servers[$k]["cpuMhz"] > $req["mincpuMhz"]
                            )
                        )
                    ) {
                        //ok lets answer ;)
                        $foundServer[] = $servers[$k];
                    }
                }
            }
            $packettype = 8;  //MasterServerListResponse
            $flag = 0;
            $key = $req["pingSessionId"];
            $packetindex = 0;
            $servercount = count($foundServer);
            svar_dump($req, 5);
            conmsg("PROC found Servers: $servercount", 5);
            if ($servercount == 0) {
                $packettotal = 1;
                $packet = pack(
                    "CCVCCvCCCCv",
                    $packettype,
                    $flag,
                    $key,
                    $packetindex,
                    $packettotal,
                    $servercount,
                    0,
                    0,
                    0,
                    0,
                    0
                );
                socket_sendto($socket, $packet, strlen($packet), 0, $clientIP, $clientPort);
                conmsg("SND MasterServerListResponse empty.", 2);
            } else {
                $packettotal = $servercount;

                foreach ($foundServer as $s) {
                    $ipv4 = explode(".", $s["IP"]);
                    $packet = pack(
                        "CCVCCvCCCCv",
                        $packettype,
                        $flag,
                        $key,
                        $packetindex,
                        $packettotal,
                        $servercount,
                        $ipv4[0],
                        $ipv4[1],
                        $ipv4[2],
                        $ipv4[3],
                        $s["PORT"]
                    );
                    socket_sendto($socket, $packet, strlen($packet), 0, $clientIP, $clientPort);
                    conmsg("SND MasterServerListResponse to $clientId SERVER:" . $s["IP"] . "::" . $s["PORT"], 2);
                    $packetindex++;
                }
            }
            unset($foundServer);
            break;

        case 12:
            conmsg("RCV GameMasterInfoResponse from $clientId", 2);
            $servers[$clientId]["expire"] = time() + $_config["timeout"];
            $servers[$clientId]["IP"] = $clientIP;
            $servers[$clientId]["PORT"] = $clientPort;

            $data = unpack("Ctype/Cflags/Vkeys/Cgtlen", $buf);
            $needle = 7;
            $data[keys] = sprintf("%u", $data[keys]); //force unsigned
            $servers[$clientId]["gametype"] = substr($buf, 7, $data["gtlen"]);
            $needle += $data["gtlen"];
            $tmpdata = unpack("C", substr($buf, $needle, 1));
            $needle++;
            $servers[$clientId]["missiontype"] = substr($buf, $needle, $tmpdata[1]);
            $needle += $tmpdata[1];
            unset($tmpdata);
            $data = unpack(
                "CmaxPlayers/VregionMask/VversionNumber/CfilterFlags/CbotCount/VcpuMhz/CplayerCount",
                substr($buf, $needle, 16)
            );
            $needle += 16;
            //Note: rest is followed up with guidList stuff.. i ignore this here
            $servers[$clientId] = array_merge($servers[$clientId], $data);
            unset($data);

            svar_dump($servers, 5);
            break;

        case 22:
            conmsg("RCV GameHeartbeat from $clientId", 2);
            $servers[$clientId]["expire"] = time() + $_config["timeout"];
            $servers[$clientId]["IP"] = $clientIP;
            $servers[$clientId]["PORT"] = $clientPort;
            $packet = pack("CCV", 10, 0, 0); //flag and key to zero added
            conmsg("SND GameMasterInfoRequest to $clientId", 5);
            socket_sendto($socket, $packet, strlen($packet), 0, $clientIP, $clientPort);

            break;
        default:
            conmsg("RCV unknown commandtype = $cmdtype from $clientId", 1);

            break;
    }
} //while true

conmsg("MasterServer shutting down.", 2);
@socket_close($socket);
