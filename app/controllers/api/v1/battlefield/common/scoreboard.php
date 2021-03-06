<?php namespace ADKGamers\Webadmin\Controllers\Api\v1\Battlefield\Common;

/**
 * Copyright 2013 A Different Kind LLC. All rights reserved.
 *
 * Development by Prophet
 *
 * Version 1.5.0
 * 8-APR-2014
 */
use ADKGamers\Webadmin\Libs\BF3Conn;
use ADKGamers\Webadmin\Libs\BF4Conn;
use ADKGamers\Webadmin\Libs\Helpers\Battlefield AS BFHelper;
use ADKGamers\Webadmin\Libs\Helpers\Main AS Helper;
use ADKGamers\Webadmin\Models\Battlefield\Player;
use ADKGamers\Webadmin\Models\Battlefield\Server;
use ADKGamers\Webadmin\Models\Battlefield\Setting AS GameSetting;
use BattlefieldException, Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Zizaco\Entrust\EntrustFacade AS Entrust;

class Scoreboard extends \BaseController
{
    /**
     * Game identifier from database
     * @var integer
     */
    private $_gameid = NULL;

    /**
     * Game abraviation
     * @var string
     */
    private $game = '';

    /**
     * Server identifier in database
     * @var integer
     */
    private $server_id = NULL;

    /**
     * List of pre-defined messages from AdKats
     * @var array
     */
    private $presetMsgs = array();

    /**
     * Server IP Address
     * @var string
     */
    private $server_ip = '0.0.0.0';

    /**
     * Server RCON Port
     * @var integer
     */
    private $server_port = 47200;

    /**
     * Variable to hold the rendered data
     * @var array
     */
    private $data = array();

    /**
     * Variable to assign to game connection class
     * @var object
     */
    private $conn;

    /**
     * Response to send back
     * @var array
     */
    private $finalized = array();

    public function __construct(Server $server = NULL)
    {
        $this->initialize($server);
    }

    /**
     * Initialize function
     *
     * @return array
     */
    private function initialize($server = FALSE)
    {
        try
        {
            // Did we get a result?
            if(!$server)
                throw new BattlefieldException("Server not found or enabled");

            $this->_gameid   = $server->GameID;
            $this->server_id = $server->ServerID;
            $this->game      = strtoupper($server->gameIdent());

            // Parse out the Hostname/IP without port number
            $this->server_ip = Helper::getIpAddr($server->IP_Address);

            // Get the port number from the Hostname/IP
            $this->server_port = Helper::getPort($server->IP_Address);

            if(!Validator::make(array('ip' => $this->server_ip), array('ip' => 'IP'))->passes())
                throw new BattlefieldException("Invalid IP Address: " . $this->server_ip);

            switch($this->game)
            {
                case "BF3":
                    $this->data['isBF3'] = TRUE;
                    $this->data['isBF4'] = FALSE;
                    $this->conn = new BF3Conn(array($this->server_ip, $this->server_port, null));
                break;

                case "BF4":
                    $this->data['isBF3'] = FALSE;
                    $this->data['isBF4'] = TRUE;
                    $this->conn = new BF4Conn(array($this->server_ip, $this->server_port, null));
                break;

                default:
                    throw new BattlefieldException("Invalid Game Ident");
            }

            // Check if we are connected to the gameserver
            if(!$this->conn->isConnected())
                throw new BattlefieldException("Could not establish connection to game server: " . trim( $server->ServerName ) );

            $gsetting = $server->setting;

            if(!$gsetting)
                throw new BattlefieldException("Missing server configuration");

            // Attempt to login to the gameserver
            $this->conn->loginSecure($gsetting->getPass());

            if(!$this->conn->isLoggedIn())
            {
                throw new BattlefieldException("Incorrect RCON password");
            }

            switch($this->game)
            {
                case "BF3":
                    $this->fetchBf3GameData();
                break;

                case "BF4":
                    $this->fetchBf4GameData();
                break;
            }

            if(Input::has('raw') && Input::get('raw') == 1) $this->_addRaw();

            $this->data['_permission'] = $this->_permissionCheck();

            if($this->data['_permission']['bf3'] || $this->data['_permission']['bf4'])
            {
                foreach ($server->adkatsConfig as $k => $v)
                {
                    if($v->setting_name == "Pre-Message List")
                    {
                        $this->presetMsgs = explode( '|', urldecode( rawurldecode( $v->setting_value ) ) );
                        break;
                    }
                }
            }

            $this->data['_premessages'] = $this->presetMsgs;

            $this->finalized = Helper::response('success', 'OK', $this->data);
        }
        catch(BattlefieldException $e)
        {
            $this->finalized = Helper::response('error', $e->getMessage(), [
                'line' => $e->getLine()
            ]);
        }
        catch(Exception $e)
        {
            $this->finalized = Helper::response('error', $e->getMessage(), [
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Fetch and prepare data for Battlefield 3
     *
     * @return void
     */
    private function fetchBf3GameData()
    {
        $serverinfo = $this->conn->getServerInfo();

        if(count($serverinfo) < 25)
        {
            $uptime = $serverinfo[15];
            $round = $serverinfo[16];
        }
        else
        {
            $uptime = $serverinfo[16];
            $round  = $serverinfo[17];
        }

        switch($serverinfo[4])
        {
            case "TeamDeathMatch0":
                $ticketcap = count($serverinfo) < 25 ? NULL : intval($serverinfo[11]);
            break;

            case "ConquestLarge0":
            case "ConquestSmall0":
                $ticketcap = count($serverinfo) < 25 ? NULL : intval($serverinfo[11]);
            break;

            case "RushLarge0":
                $ticketcap = count($serverinfo) < 25 ? NULL : intval($serverinfo[11]);
            break;

            default:
                $ticketcap = NULL;
            break;
        }

        $startTickets = BFHelper::getStartTicketCount($this->conn->getCurrentPlaymode(), $this->conn->adminVarGetGameModeCounter(), $this->game);

        $this->data['serverinfo'] = array(
            'server_name'      => $this->conn->getServerName(),
            'description'      => trim($this->conn->adminVarGetServerDescription()),
            'current_players'  => $this->conn->getCurrentPlayers(),
            'total_players'    => $this->conn->getMaxPlayers(),
            'map'              => last($this->conn->getCurrentMapName()),
            'nextmap'          => $this->_getNextMap(),
            'gamemode_uri'     => $this->conn->getCurrentPlaymode(),
            'gamemode'         => last($this->conn->getCurrentPlaymodeName()),
            'ticket_cap'       => $ticketcap,
            'starting_tickets' => $startTickets,
            'times' => array(
                'round'     => Helper::convertSecToStr($round, true),
                'uptime'    => Helper::convertSecToStr($uptime, true)
            )
        );

        $this->data['teaminfo'][1]['ticketcount'] = (integer) $serverinfo[9];
        $this->data['teaminfo'][2]['ticketcount'] = (integer) $serverinfo[10];
        $this->data['teaminfo'][1]['faction']     = ['full_name' => 'US Army'];
        $this->data['teaminfo'][2]['faction']     = ['full_name' => 'Russian Army'];
        $this->data['teaminfo'][1]['playerlist']  = [];
        $this->data['teaminfo'][2]['playerlist']  = [];
        $this->data['online_admins']              = [];

        switch($serverinfo[4])
        {
            case "SquadDeathMatch0":
                $this->data['teaminfo'][3]['ticketcount'] = (integer) $serverinfo[11];
                $this->data['teaminfo'][4]['ticketcount'] = (integer) $serverinfo[12];
                $this->data['teaminfo'][3]['faction']     = ['full_name' => 'Unknown'];
                $this->data['teaminfo'][4]['faction']     = ['full_name' => 'Unknown'];
                $this->data['teaminfo'][3]['playerlist']  = [];
                $this->data['teaminfo'][4]['playerlist']  = [];
            break;
        }

        if($this->conn->getCurrentPlayers() > 0)
        {
            $this->buildPlayerListing();

            $this->_checkForAdmins();
        }
    }

    /**
     * Fetch and prepare data for Battlefield 4
     *
     * @return void
     */
    private function fetchBf4GameData()
    {
        $serverinfo = $this->conn->getServerInfo();

        if($this->conn->getCurrentPlaymode() == "TeamDeathMatch0")
        {
            if(count($serverinfo) < 28)
            {
                $round = $serverinfo[15];
                $uptime = $serverinfo[14];
            }
            else
            {
                $round = $serverinfo[19];
                $uptime = $serverinfo[18];
            }
        }
        else
        {
            if(count($serverinfo) < 26)
            {
                $round = $serverinfo[15];
                $uptime = $serverinfo[14];
            }
            else
            {
                $round = $serverinfo[17];
                $uptime = $serverinfo[16];
            }
        }

        switch($serverinfo[4])
        {
            case "TeamDeathMatch0":
                $ticketcap = count($serverinfo) < 28 ? NULL : intval($serverinfo[13]);
            break;

            case "CaptureTheFlag0":
                $ticketcap = NULL;
            break;

            case "Obliteration":
            case "Chainlink0":
            case "RushLarge0":
            case "Domination0":
            case "ConquestLarge0":
            case "ConquestSmall0":
                $ticketcap = count($serverinfo) < 26 ? NULL : intval($serverinfo[11]);
            break;

            default:
                $ticketcap = NULL;
            break;
        }

        $startTimer   = BFHelper::getStartRoundTimer($this->conn->getCurrentPlaymode(), $this->conn->adminVarGetRoundTimeLimit(), $this->game);
        $startTickets = BFHelper::getStartTicketCount($this->conn->getCurrentPlaymode(), $this->conn->adminVarGetGameModeCounter(), $this->game);

        $this->data['serverinfo'] = array(
            'server_name'      => $this->conn->getServerName(),
            'description'      => trim($this->conn->adminVarGetServerDescription()),
            'current_players'  => $this->conn->getCurrentPlayers(),
            'total_players'    => $this->conn->getMaxPlayers(),
            'map'              => last($this->conn->getCurrentMapName()),
            'nextmap'          => $this->_getNextMap(),
            'gamemode_uri'     => $this->conn->getCurrentPlaymode(),
            'gamemode'         => last($this->conn->getCurrentPlaymodeName()),
            'ticket_cap'       => $ticketcap,
            'starting_tickets' => $startTickets,
            'starting_timer'   => Helper::convertSecToStr($startTimer),
            'times' => array(
                'round'     => Helper::convertSecToStr($round, true),
                'uptime'    => Helper::convertSecToStr($uptime, true),
                'remaining' => ($this->conn->getCurrentPlayers() >= 4 ? Helper::convertSecToStr($startTimer - $round, true) : 'PreRound')
            )
        );

        $this->data['teaminfo'][1]['ticketcount'] = intval($serverinfo[9]);
        $this->data['teaminfo'][2]['ticketcount'] = intval($serverinfo[10]);
        $this->data['teaminfo'][1]['faction']     = $this->conn->adminVarGetTeamFaction(1);
        $this->data['teaminfo'][2]['faction']     = $this->conn->adminVarGetTeamFaction(2);
        $this->data['teaminfo'][1]['playerlist']  = [];
        $this->data['teaminfo'][2]['playerlist']  = [];
        $this->data['teaminfo'][1]['commander']   = [];
        $this->data['teaminfo'][2]['commander']   = [];
        $this->data['teaminfo'][0]['spectators']  = [];
        $this->data['online_admins']              = [];

        switch($serverinfo[4])
        {
            case "SquadDeathMatch0":
                $this->data['teaminfo'][3]['ticketcount'] = intval($serverinfo[11]);
                $this->data['teaminfo'][4]['ticketcount'] = intval($serverinfo[12]);
                $this->data['teaminfo'][3]['faction']     = $this->conn->adminVarGetTeamFaction(3);
                $this->data['teaminfo'][4]['faction']     = $this->conn->adminVarGetTeamFaction(4);
                $this->data['teaminfo'][3]['playerlist']  = [];
                $this->data['teaminfo'][4]['playerlist']  = [];
            break;
        }

        $this->buildPlayerListing();

        $this->_checkForAdmins();
    }

    /**
     * Returns the data
     * @return array
     */
    public function get()
    {
        return $this->finalized;
    }

    /**
     * Checks the database for the players id or adds them
     * @param  string $eaguid
     * @param  string $soldier Player Name
     * @return integer
     */
    private function _playerExist($eaguid = NULL, $soldier = NULL)
    {
        if(empty($eaguid)) return NULL;

        $player_id = Player::where('GameID', $this->_gameid)->where('EAGUID', $eaguid)->pluck('PlayerID');

        if(!$player_id)
        {
            $newPlayer = new Player;
            $newPlayer->GameID      = $this->_gameid;
            $newPlayer->EAGUID      = $eaguid;
            $newPlayer->SoldierName = $soldier;
            $newPlayer->save();

            $player_id = $newPlayer->PlayerID;
        }

        return intval($player_id);
    }

    /**
     * Checks for any admins currently in the server and adds them to the online admins array
     * @return void
     */
    private function _checkForAdmins()
    {
        $admins = DB::select(File::get(storage_path() . '/sql/adkats_role_is_admin.sql'));

        for($i=0; $i <= count($this->data['teaminfo']); $i++)
        {
            if(!empty($this->data['teaminfo'][$i]['playerlist']))
            {
                foreach($this->data['teaminfo'][$i]['playerlist'] as $player)
                {
                    foreach($admins as $admin)
                    {
                        if(array_key_exists('player_id', $player) === FALSE) continue;

                        if($player['player_id'] == $admin->player_id && $admin->GameID == $this->_gameid)
                        {
                            $this->data['online_admins'][] = [
                                'player_name' => $player['player_name'],
                                'player_id' => $player['player_id']
                            ];
                        }
                    }
                }
            }
        }

        if(!empty($this->data['teaminfo'][0]['spectators']))
        {
            foreach($this->data['teaminfo'][0]['spectators'] as $player)
            {
                foreach($admins as $admin)
                {
                    if(array_key_exists('player_id', $player) === FALSE) continue;

                    if($player['player_id'] == $admin->player_id && $admin->GameID == $this->_gameid)
                    {
                        $this->data['online_admins'][] = [
                            'player_name' => $player['player_name'],
                            'player_id' => $player['player_id']
                        ];
                    }
                }
            }
        }
    }

    /**
     * Handles the player listing compline for both games
     * @return void
     */
    private function buildPlayerListing()
    {
        $err = 0;

        $players = $this->conn->adminGetPlayerlist();

        $squadStatus[0] = [];
        $squadStatus[1] = [];
        $squadStatus[2] = [];
        $squadStatus[3] = [];
        $squadStatus[4] = [];

        if(count($players) > 13 && $this->data['serverinfo']['current_players'] == 0)
        {
            switch(count($players))
            {
                case 23:
                    $loop_count = 1;
                break;

                case 33:
                    $loop_count = 2;
                break;

                case 43:
                    $loop_count = 3;
                break;

                case 53:
                    $loop_count = 4;
                break;
            }
        }
        else
        {
            if($this->game == 'BF4')
            {
                $loop_count = $players[12];
            }
            elseif($this->game == 'BF3')
            {
                $loop_count = $players[10];
            }
        }

        for($i=0; $i < $loop_count; $i++)
        {
            try
            {
                $player_deaths       = intval( $players[ ( $players[1] ) * $i + $players[1] + 8 ] );
                $player_guid         = $players[ ( $players[1] ) * $i + $players[1] + 4 ];
                $player_kills        = intval( $players[ ( $players[1] ) * $i + $players[1] + 7 ] );
                $player_rank         = intval( $players[ ( $players[1] ) * $i + $players[1] + 10 ] );
                $player_score        = intval( $players[ ( $players[1] ) * $i + $players[1] + 9 ] );
                $player_soldier_name = $players[ ( $players[1] ) * $i + $players[1] + 3 ];
                $player_squad_id     = intval( $players[ ( $players[1] ) * $i + $players[1] + 6 ] );
                $player_team_id      = intval( $players[ ( $players[1] ) * $i + $players[1] + 5 ] );

                if($player_team_id != 0 && $player_squad_id != 0)
                {
                    if(array_key_exists($player_squad_id, $squadStatus[$player_team_id]) === FALSE)
                        $squadStatus[$player_team_id][$player_squad_id] = $this->conn->adminIsSquadPrivate($player_team_id, $player_squad_id);

                    $isSquadPrivate = $squadStatus[$player_team_id][$player_squad_id];
                } else $isSquadPrivate = NULL;

                if($this->game == 'BF4')
                {
                    $player_ping = intval( $players[ ( $players[1] ) * $i + $players[1] + 11 ] );
                    $player_type = intval( $players[ ( $players[1] ) * $i + $players[1] + 12 ] );

                    if($player_type == 1)
                    {
                        $this->data['teaminfo'][0]['spectators'][] = array(
                            'player_id'   => $player_guid,
                            'player_name' => $player_soldier_name,
                            'isGhost'     => empty($player_guid) ? TRUE : FALSE
                        );

                        continue;
                    }

                    if($player_type == 2 || $player_type == 3)
                    {
                        $this->data['teaminfo'][$player_team_id]['commander'] = array(
                            'player_id'    => $player_guid,
                            'player_name'  => $player_soldier_name,
                            'player_score' => $player_score,
                            'isGhost'      => empty($player_guid) ? TRUE : FALSE
                        );

                        continue;
                    }
                }

                $this->data['teaminfo'][$player_team_id]['playerlist'][] = array(
                    'player_id'            => $player_guid,
                    'player_deaths'        => $player_deaths,
                    'player_kills'         => $player_kills,
                    'player_score'         => $player_score,
                    'player_name'          => $player_soldier_name,
                    'player_team'          => $player_team_id,
                    'player_squad'         => BFHelper::squad($player_squad_id),
                    'player_squad_id'      => $player_squad_id,
                    'player_squad_private' => $isSquadPrivate,
                    'player_ping'          => (isset($player_ping) ? $player_ping : NULL),
                    'player_rank'          => (isset($player_rank) ? $player_rank : NULL),
                    'player_kdr'           => BFHelper::calculKDRatio($player_kills, $player_deaths),
                    'rank_image'           => (isset($player_rank) ? self::_getRankImage($player_rank) : NULL),
                    'country'              => NULL,
                    'isGhost'              => empty($player_guid) ? TRUE : FALSE
                );
            }
            catch(Exception $e)
            {
                if(Config::get('app.debug'))
                {
                    $err++;
                    $this->data['errors']['messages'][] = array($e->getMessage(), $e->getLine());
                }
            }
        }

        if(Config::get('app.debug'))
            $this->data['errors']['count'] = $err;

        $this->_queryPlayerData();
    }

    /**
     * Querys the database for the players ID
     * @return void
     */
    public function _queryPlayerData()
    {
        if($this->data['serverinfo']['current_players'] == 0)
            return false;

        $players = [];

        foreach($this->data['teaminfo'] as $team)
        {
            if(array_key_exists('playerlist', $team))
            {
                foreach($team['playerlist'] as $player)
                {
                    $players[] = $player['player_id'];
                }
            }

            if(array_key_exists('spectators', $team))
            {
                foreach($team['spectators'] as $player)
                {
                    $players[] = $player['player_id'];
                }
            }
        }

        if(empty($players))
            return false;

        $players_query = Player::where('GameID', $this->_gameid)->whereIn('EAGUID', $players)->get();

        foreach($this->data['teaminfo'] as $teamid => $team)
        {
            if(array_key_exists('playerlist', $team))
            {
                foreach($team['playerlist'] as $key => $player)
                {
                    foreach($players_query as $pinfo)
                    {
                        if($player['player_id'] == $pinfo->EAGUID)
                        {
                            $this->data['teaminfo'][$teamid]['playerlist'][$key]['player_id'] = $pinfo->PlayerID;

                            if(!empty($pinfo->CountryCode) && $pinfo->CountryName != FALSE)
                            {
                                $this->data['teaminfo'][$teamid]['playerlist'][$key]['country']   = [
                                    'image' => $pinfo->CountryCode . '.png',
                                    'path'  => asset('img/flags'),
                                    'name'  => $pinfo->CountryName
                                ];
                            }

                            break;
                        }
                    }
                }
            }

            if(array_key_exists('spectators', $team))
            {
                foreach($team['spectators'] as $key => $player)
                {
                    foreach($players_query as $pinfo)
                    {
                        if($player['player_id'] == $pinfo->EAGUID)
                        {
                            $this->data['teaminfo'][$teamid]['spectators'][$key]['player_id'] = $pinfo->PlayerID;

                            if(!empty($pinfo->CountryCode) && $pinfo->CountryName != FALSE)
                            {
                                $this->data['teaminfo'][$teamid]['spectators'][$key]['country']   = [
                                    'image' => $pinfo->CountryCode . '.png',
                                    'path'  => asset('img/flags'),
                                    'name'  => $pinfo->CountryName
                                ];
                            }

                            break;
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns the image file needed to display the rank icon
     * @param  integer $rank
     * @return string
     */
    public function _getRankImage($rank)
    {
        switch($this->game)
        {
            case "BF3":
                if($rank > 45) {
                    if($rank > 100) $rank = 100;
                    $image = sprintf("ss%u.png", $rank);
                } else {
                    $image = sprintf("r%u.png", $rank);
                }
            break;

            case "BF4":
                $image = sprintf("r%u.png", $rank);
            break;

            default:
                $image = NULL;
        }

        return $image;
    }

    /**
     * Returns the next map in rotation
     * @return array
     */
    public function _getNextMap()
    {
        $nextMapIndex = $this->conn->adminMaplistGetNextMapIndex();

        $maplist = $this->_getMapList();

        foreach($maplist as $map)
        {
            if($map['index'] == $nextMapIndex)
            {
                return $map;
            }
        }

        return NULL;
    }

    /**
     * Gets the maplist from the game server
     * @return array
     */
    public function _getMapList()
    {
        $maplist = $this->conn->adminMaplistList();

        switch($this->game)
        {
            case "BF3":
                $filePath = app_path() . "/thirdparty/bf3/mapNames.xml";
                $filePath2 = app_path() . "/thirdparty/bf3/playModes.xml";
            break;

            case "BF4":
                $filePath = app_path() . "/thirdparty/bf4/mapNames.xml";
                $filePath2 = app_path() . "/thirdparty/bf4/playModes.xml";
            break;
        }

        $listing = [];

        for($i=0; $i < $maplist[1]; $i++)
        {
            try
            {
                $map       = $maplist[ ($maplist[2]) * $i + $maplist[2] ];
                $mode      = $maplist[ ($maplist[2]) * $i + $maplist[2] + 1];
                $round_num = $maplist[ ($maplist[2]) * $i + $maplist[2] + 2];

                $listing[] = [
                    'map'    => [
                        'friendlyName' => head(BFHelper::getMapName($map, $filePath)),
                        'uri' => $map
                    ],
                    'mode'   => [
                        'friendlyName' => head(BFHelper::getPlaymodeName($mode, $filePath2)),
                        'uri' => $mode
                    ],
                    'rounds' => intval($round_num),
                    'index'  => intval($i)
                ];
            }
            catch(Exception $e) {}
        }

        return $listing;
    }

    /**
     * Added the raw information from the game server.
     * Used for debugging
     */
    public function _addRaw()
    {
        $serverinfo = $this->conn->getServerInfo();

        $this->data['raw']['playerlist'] = $this->conn->adminGetPlayerlist();

        for($i=0; $i < count($serverinfo); $i++)
        {
            $key = 'K' . $i;
            $this->data['raw']['serverinfo'][$key] = $serverinfo[$i];

            if(is_numeric($this->data['raw']['serverinfo'][$key]))
            {
                $this->data['raw']['serverinfo'][$key] = intval($this->data['raw']['serverinfo'][$key]);
            }
            else
            {
                if($this->data['raw']['serverinfo'][$key] == 'true' || $this->data['raw']['serverinfo'][$key] == 'false')
                {
                    $this->data['raw']['serverinfo'][$key] = ($this->data['raw']['serverinfo'][$key] == 'true' ? true : false);
                }
            }
        }
    }

    /**
     * Returns the permissions the user is allowed to access
     * @return array
     */
    public function _permissionCheck()
    {
        $temp = array(
            'ban'     => FALSE,
            'bf3'     => FALSE,
            'bf4'     => FALSE,
            'forgive' => FALSE,
            'kick'    => FALSE,
            'kickall' => FALSE,
            'kill'    => FALSE,
            'nuke'    => FALSE,
            'pmute'   => FALSE,
            'psay'    => FALSE,
            'punish'  => FALSE,
            'pyell'   => FALSE,
            'say'     => FALSE,
            'squad'   => FALSE,
            'tban'    => FALSE,
            'team'    => FALSE,
            'tsay'    => FALSE,
            'tyell'   => FALSE,
            'yell'    => FALSE,
        );

        if(!\Auth::check()) return $temp;

        $user_permissions = \Auth::user()->permissions();

        foreach($user_permissions as $permission)
        {
            if(starts_with($permission->name, 'scoreboard'))
            {
                $cmdname = explode('.', $permission->name);

                if(array_key_exists($cmdname[1], $temp))
                {
                    $temp[$cmdname[1]] = TRUE;
                }
            }
        }

        return $temp;
    }
}
