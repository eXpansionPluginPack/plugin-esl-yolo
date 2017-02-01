<?php

namespace ManiaLivePlugins\ESL\YOLOcup;

use Exception;
use ManiaLive\Data\Player;
use ManiaLive\Gui\CustomUI;
use ManiaLivePlugins\ESL\YOLOcup\Gui\Widgets\CupInfo;
use ManiaLivePlugins\ESL\YOLOcup\Gui\Widgets\Scoretable;
use ManiaLivePlugins\ESL\YOLOcup\Structures\CupScore;
use ManiaLivePlugins\eXpansion\AdminGroups\AdminGroups;
use ManiaLivePlugins\eXpansion\AdminGroups\Permission;
use ManiaLivePlugins\eXpansion\Core\types\ExpPlugin;
use ManiaLivePlugins\eXpansion\Helpers\ArrayOfObj;
use ManiaLivePlugins\eXpansion\Helpers\Console;
use Maniaplanet\DedicatedServer\Structures\GameInfos;

/**
 * ESL YOLOcup
 *
 * Purpose of this plugin is to enhance the native cupmode to YOLOcup ruleset
 *
 * @author Reaby
 */
class YOLOcup extends ExpPlugin
{
#region Variables
    /** @var CupScore[] $cupScores holds the cup scores, sorted by greatest score */
    private $cupPlayers = array();

    /** @var bool $enabled used to flag if mode is enabled */
    private $enabled = false;

    /** @var Player[] holds the players who finished this round in order of arrival */
    private $roundFinish = array();

    /** @var bool is the YOLOcup in practice mode */
    private $practiceMode = false;

    /** @var string used to check when map rotation is starting over */
    private $startingMapUid = "";

    /** @var integer used to mark the phase round */
    private $phaseNumber = -1;

    /** @var integer used to mark the round */
    private $roundNumber = -1;

    /** @var integer used to mark the mapindex for phase */
    private $mapNumber = -1;

    /** @var 2 dimensional array, hold current map top scores as in format: array[$login][$mapNumber] => int $score */
    private $mapTopScores = array();

    /** @var boolean */
    private $wasWarmup = false;

    /** @var string */
    private $scoretableLayer = "scorestable";

    /** @var bool is the cup just started ? */
    private $justStarted = true;

    /** @var CupScore[] $top4Players */
    private $top4Players = array();

    /** @var int */
    private $roundInMap = -1;

    /** @var bool */
    private $endYOLOcup = false;

    /** @var int */
    private $startTime = 0;

    /**
     * @var bool fix for ro4
     */
    private $notStarted = true;
    /**
     * @var int
     */
    private $ro4Counter = 2;

    private $ro4CurrentPlayer = "";

#endregion

#region eXp callbacks

    /**
     * callback when plugin is ready
     */
    public function eXpOnReady()
    {
        $this->registerChatCommand("t", "nextPhase", 0, false, AdminGroups::get());
        $this->registerChatCommand("d", "setSpectate", 1, false, AdminGroups::get());
        $this->registerChatCommand("g", "genTimes", 0, false, AdminGroups::get());

        AdminGroups::addAdminCommand('yolo', $this, 'admYoloCup', Permission::GAME_GAMEMODE);
        $this->enableDedicatedEvents();
        $this->enableTickerEvent();

    }

    /**
     * callback for unloading
     */
    public function eXpOnUnload()
    {
        $this->showUI();
        $this->connection->setCupPointsLimit(100);

        $ag = AdminGroups::getInstance();
        $ag->unregisterChatCommand("yolocup");
        $this->reset();
        $this->enabled = false;
        $this->roundFinish = array();
        Scoretable::EraseAll();
        CupInfo::EraseAll();
    }

    /**
     * @param $fromLogin
     * @param $params
     */
    function admYoloCup($fromLogin, $params)
    {
        try {
            $command = array_shift($params);

            switch (strtolower($command)) {
                case "prac":
                case "practice":
                    $this->reset();
                    $this->connection->setGameMode(GameInfos::GAMEMODE_TIMEATTACK);
                    $this->connection->setTimeAttackLimit(5 * 60 * 1000);
                    $this->practiceMode = true;
                    $this->showUI();
                    $this->connection->nextMap(false);
                    break;
                case "rel":
                    $this->releaseSpec();
                    break;
                case "start":
                    $this->reset();
                    Scoretable::EraseAll();
                    CupInfo::EraseAll();
                    $this->enabled = true;
                    $this->justStarted = true;
                    $this->startingMapUid = $this->storage->nextMap->uId;
                    $this->connection->setGameMode(GameInfos::GAMEMODE_CUP);
                    $this->connection->setCupPointsLimit(1000);
                    $this->hideUI();
                    $this->releaseSpec();
                    $this->registerPlayers();
                    $this->setupRules();
                    $this->connection->nextMap();
                    break;
                case "stop":
                    $this->reset();
                    Scoretable::EraseAll();
                    CupInfo::EraseAll();
                    $this->showUI();
                    $this->releaseSpec();
                    $this->connection->setCupPointsLimit(100);
                    $this->eXpChatSendServerMessage("YOLOcup disabled - cup point limit set to 100");
                    break;
                default:
                    $this->eXpChatSendServerMessage("command not found", $fromLogin);
                    break;
            }
        } catch (Exception $e) {

        }
    }

#endregion

#region Dedicated Server callbacks

    /**
     * on every 1 second
     */
    public function onTick()
    {
        if ($this->startTime > 0 && $this->calcRo() == 4) {
            echo ".";
            if (time() >= $this->startTime + 5) {
                // disabling the counter
                $this->startTime = 0;
                // set next player to play
                if (array_key_exists($this->roundInMap, $this->top4Players)) {
                    $playerInTurn = $this->top4Players[$this->roundInMap];
                    $this->setPlayerToDrive($playerInTurn);
                }
            } else {
                $diff = 5 - (time() - $this->startTime);
                $this->eXpChatSendServerMessage('Starting in $0f0' . $diff);
            }
        }
    }


    /**
     * @param string $login
     * @param bool $isSpec
     */
    public function onPlayerConnect($login, $isSpec)
    {

        if ($this->practiceMode == true) {
            $this->checkPractise();
            return;
        }

        if ($this->enabled == false) return;

        /* create cupscore objects and update player status */
        $player = $this->storage->getPlayerObject($login);
        if (!array_key_exists($player->login, $this->cupPlayers)) {
            $this->cupPlayers[$player->login] = new CupScore($player);
            $this->cupPlayers[$player->login]->isPlaying = false;
            $this->cupPlayers[$player->login]->isConnected = true;
        } else {
            $this->cupPlayers[$player->login]->isConnected = true;
        }

        /* if the player is not granted to play, force spectator */
        if ($this->cupPlayers[$player->login]->isPlaying == false) {
            $this->connection->forceSpectator($login, 1);
        }
        $this->Scoretable();
    }

    /**
     * @param string $login
     * @param null $disconnectionReason
     */
    public function onPlayerDisconnect($login, $disconnectionReason = null)
    {
        if ($this->enabled == false) return;

        if (array_key_exists($login, $this->cupPlayers)) {

            $this->cupPlayers[$login]->isConnected = false;
            if ($this->calcRo() == 4 && in_array($login, $this->top4Players)) {
                $this->cupPlayers[$login]->isPlaying = false;
                $newTop4 = array();
                foreach ($this->top4Players as $idx => $login2) {
                    $newTop4[] = $login2;
                }
                $this->top4Players = $newTop4;
                if ($this->ro4CurrentPlayer == $login) {
                    $this->eXpChatSendServerMessage("Player in turn disconnected, ending round.");
                    $this->setupRo4Players();

                    $this->ro4Counter = -1;
                    $this->startTime = time();

                }
            }
        }
        $this->Scoretable();
    }

    /**
     *
     *
     * @param int $playerUid
     * @param string $login
     * @param int $timeOrScore
     *
     */
    public function onPlayerFinish($playerUid, $login, $timeOrScore)
    {
        if ($this->enabled == false || $this->practiceMode == true) return;
        if ($timeOrScore == 0) {
            return;
        }

        $player = new Player();
        $player->playerId = $playerUid;
        $player->login = $login;
        $player->rank = count($this->roundFinish) + 1;
        $player->score = $timeOrScore;
        $this->roundFinish[] = $player;
    }

    /**
     * @param $map
     * @param bool $warmUp
     * @param bool $matchContinuation
     */
    public function onBeginMap($map, $warmUp, $matchContinuation)
    {
        $this->notStarted = true;

        if ($this->practiceMode == TRUE) {
            return;
        }
        if ($this->enabled == FALSE) return;

        // if no starting map... set one
        if (empty($this->startingMapUid)) $this->startingMapUid = $this->storage->currentMap->uId;
        if ($warmUp) return;

        $this->roundInMap = -1;
        $this->mapNumber++;

        $this->eXpChatSendServerMessage(eXpGetMessage('YOLOcup: $0d0 LIVE - RO %s Starting round %s'), null, array($this->calcRo(), ($this->roundNumber + 1)));

        $this->scoretableLayer = "scorestable";
        $this->scoreTable();
    }

    /**
     * @param array $rankings
     * @param object $map
     * @param bool $wasWarmUp
     * @param bool $matchContinuesOnNextMap
     * @param bool $restartMap
     */
    public function onEndMap($rankings, $map, $wasWarmUp, $matchContinuesOnNextMap, $restartMap)
    {
        $this->notStarted = true;
        if ($this->practiceMode == true) return;
        if ($this->enabled == false) return;
        if ($this->justStarted == true) {
            $this->justStarted = false;
            return;
        }

        $this->eXpChatSendServerMessage(eXpGetMessage('YOLOcup: $0d0 LIVE - END of map #variable#%s'), null, array($this->mapNumber + 1));

        $this->scoretableLayer = "scorestable";
        $this->scoreTable();
    }

    /**
     *
     */
    public function onBeginMatch()
    {
        $this->notStarted = false;
        $this->scoretableLayer = "scorestable";
        $this->showStatusWidget();
    }

    /**
     * @param array $rankings
     * @param array $winnerTeamOrMap
     */
    public function onEndMatch($rankings, $winnerTeamOrMap)
    {
        $this->notStarted = true;
        CupInfo::EraseAll();
        if ($this->practiceMode == true) return;
        if ($this->enabled == false) return;
        if ($this->wasWarmup) return;

        $this->setupRules();

        if ($this->justStarted == true) return;

        // check if next phase starts
        if ($this->storage->nextMap->uId == $this->startingMapUid) {

            $this->phaseNumber++;
            $this->mapNumber = -1;
            $this->roundNumber = -1;

            if ($this->phaseNumber < 0) {
                $this->scoretableLayer = "normal";
                $this->scoreTable();
                return;
            }

            // if ro greater than 4
            $ro = $this->calcRo();

            if ($ro > 4) {
                $this->eXpChatSendServerMessage("[YOLOcup] End of RO " . $ro);
                $this->nextPhase();
                $this->scoretableLayer = "normal";
                $this->scoreTable();
            }

            if ($this->calcRo() == 4) {
                $this->d("forceSpec", __FUNCTION__);
                $this->forceSpecAll();
            }

            if ($ro == 4 && $this->endYOLOcup == true) {
                $this->eXpChatSendServerMessage("YoloCup ends. Thanks for participating.");
                Scoretable::EraseAll();
                CupInfo::EraseAll();
                $this->releaseSpec();
                $this->reset();
            }
        }

    }

    /**
     *
     */
    public function onBeginRound()
    {
        $this->wasWarmup = $this->connection->getWarmUp();

        if ($this->enabled == true || $this->practiceMode == true) {
            if ($this->wasWarmup == false) {

                $this->d($this->ro4Counter, __FUNCTION__);
                if ($this->calcRo() == 4 && $this->ro4Counter >= 1) {
                    $this->roundNumber++;
                    $this->roundInMap++;
                }

                if ($this->calcRo() > 4) {
                    $this->roundNumber++;
                    $this->roundInMap++;
                }

            }

            if ($this->notStarted == true) return;

            if ($this->calcRo() == 4 && $this->ro4Counter >= 1) {
                $this->forceSpecAll();
                $this->ro4Counter = -1;
                $this->d("Set delay start", __FUNCTION__);
                $this->startTime = time();
                $this->eXpChatSendServerMessage($this->top4Players[$this->roundInMap] . '$z$s next in turn');
            }

            $this->showStatusWidget();
            $this->roundFinish = array();
            $this->scoretableLayer = "scorestable";
            $this->scoreTable();
        }
    }

    /**
     *
     */
    public function onEndRound()
    {
        if ($this->practiceMode == true) return;
        if ($this->wasWarmup) return;
        if ($this->enabled == false) return;
        if ($this->justStarted) return;
        $this->ro4Counter++;

        $this->d($this->ro4Counter, __FUNCTION__);
        if ($this->calcRo() == 4 && $this->ro4Counter < 1) {
            $this->d("returning");
            return;
        }

        $alreadySet = array();

        // set new personal best for map
        foreach ($this->roundFinish as $rank => $player) {
            $alreadySet[] = $player->login;
            if (array_key_exists($player->login, $this->mapTopScores)) {
                if (!array_key_exists($this->mapNumber, $this->mapTopScores[$player->login])) {
                    $this->mapTopScores[$player->login][$this->mapNumber] = $player->score;
                } elseif ($this->mapTopScores[$player->login][$this->mapNumber] > $player->score) {
                    $this->mapTopScores[$player->login][$this->mapNumber] = $player->score;
                }
            } else {
                $this->mapTopScores[$player->login][$this->mapNumber] = $player->score;
            }
        }

        if ($this->calcRo() > 4) {
            // apply dnf rules
            foreach ($this->cupPlayers as $login => $player) {
                if ($player->isPlaying) {
                    if (!in_array($login, $alreadySet)) {
                        /** @var Config $config */
                        $config = Config::getInstance();
                        $this->mapTopScores[$player->login][$this->mapNumber] = ($this->storage->currentMap->authorTime * $config->atMultiplier);
                    }
                }
            }
        } else {
            // ro4 special ruleset
            if (!in_array($this->ro4CurrentPlayer, $alreadySet)) {
                /** @var Config $config */
                $config = Config::getInstance();
                $login = $this->ro4CurrentPlayer;
                $this->mapTopScores[$login][$this->mapNumber] = ($this->storage->currentMap->authorTime * $config->atMultiplier);
            }
        }

        $this->scoretableLayer = "normal";
        $this->scoreTable();

        // if ro == 4
        $this->d("top4count: " . count($this->top4Players), __FUNCTION__);
        if ($this->roundInMap >= count($this->top4Players) - 1) {
            if ($this->storage->nextMap->uId == $this->startingMapUid) {
                $this->endYOLOcup = true;
            }
            $this->d("round more than " . count($this->top4Players) . ", skipping!");
            $this->connection->nextMap();
        }
    }



#endregion

#region Helpers


    private function setupRo4Players()
    {
        $this->top4Players = array();
        foreach ($this->cupPlayers as $login => $player) {
            if ($player->isPlaying) {
                // top4Players is now in order top 4
                $this->top4Players[] = $login;
            }
        }

        if (count($this->top4Players) > 4) {
            $this->d(count($this->top4Players), __FUNCTION__);
            $this->eXpChatSendServerMessage("[YOLOcup] Whops, more than 4 players at ro4.. reducing number to top 4");
            $this->top4Players = array_slice($this->top4Players, 0, 4, true);
        }
        // top4Players is now reversed order.. ready to cycle though rounds.
        $this->top4Players = array_reverse($this->top4Players);
    }

    private function setupRules()
    {
        switch ($this->calcRo()) {
            case 32:
                $this->connection->setCupRoundsPerMap(2);
                $this->connection->setCupWarmUpDuration(1);
                break;
            case 16:
                $this->connection->setCupRoundsPerMap(2);
                $this->connection->setCupWarmUpDuration(0);
                break;
            case 8:
                $this->connection->setCupRoundsPerMap(1);
                $this->connection->setCupWarmUpDuration(0);
                break;
            case 4:
                $this->connection->setCupRoundsPerMap(8);
                $this->connection->setCupWarmUpDuration(0);

                $this->updateScores();
                $this->setupRo4Players();
                break;
        }
    }

    /**
     * resets the variables
     */
    private function reset()
    {
        $this->roundInMap = -1;
        $this->practiceMode = false;
        $this->enabled = false;
        $this->roundNumber = -1;
        $this->mapNumber = -1;
        $this->phaseNumber = -1;
        $this->justStarted = false;
        $this->startingMapUid = "";
        $this->mapTopScores = array();
        $this->cupPlayers = array();
        $this->roundFinish = array();
        $this->top4Players = array();
        $this->notStarted = true;
        $this->endYOLOcup = false;
        $this->ro4Counter = 2;
        $this->ro4CurrentPlayer = "";
    }

    /**
     * contains checks made for practice mode
     */
    public function checkPractise()
    {
        // do double check, to be sure this is called only on practice mode
        if ($this->practiceMode == false) return;

        foreach ($this->storage->players as $login => $player) {
            // if player has been forced to spectate, release to play
            if ($player->forceSpectator == 1) {
                $this->connection->forceSpectator($login, 0);
                $this->connection->forceSpectator($login, 2);
            }
            // if author has made the current map, force to specate
            if ($this->storage->currentMap->author == $login) {
                $this->eXpChatSendServerMessage("[YOLOcup notice] You are not allowed to practice, since you're the author of this map!",
                    $login);
                $msg = "[YOLOcup notice]Player " . $player->nickName . '$z$s is forced to spec since rule: author of the map on practice mode is not allowed to play!';
                $ag = AdminGroups::getInstance();
                $ag->announceToPermission("yolo_admin", $msg);
                $this->connection->forceSpectator($login, 1);
            }
        }
    }

    /**
     *
     */
    public function updateScores()
    {
        foreach ($this->mapTopScores as $login => $score) {
            if (array_key_exists($login, $this->cupPlayers)) {
                if ($this->cupPlayers[$login]->isPlaying) {
                    $this->cupPlayers[$login]->scores = array_sum($score);
                }
            }
        }

        ArrayOfObj::asortAsc($this->cupPlayers, "scores");
    }

    /**
     * registers the players at start of the cup
     */
    public function registerPlayers()
    {
        $this->cupPlayers = Array();
        foreach ($this->storage->players as $login => $player) {
            $this->cupPlayers[$login] = new CupScore($player);
            $this->cupPlayers[$login]->isPlaying = true;
        }

        foreach ($this->storage->spectators as $login => $player) {
            $this->cupPlayers[$login] = new CupScore($player);
            $this->cupPlayers[$login]->isPlaying = true;
        }
    }


    /**
     * set all players to spectate, execpt the playing player
     * @param string $playingLogin
     */
    public function setPlayerToDrive($playingLogin)
    {
        $this->d(implode(",", $this->top4Players));
        $this->d("set to play:" . $playingLogin);

        $this->ro4CurrentPlayer = $playingLogin;
        $this->connection->forceSpectator($playingLogin, 2);
        $this->ro4Counter = -1;
        // $this->connection->forceSpectator($playingLogin, 0);

        /* foreach ($this->top4Players as $idx => $login) {
             if ($login != $playingLogin) {
                 $this->connection->forceSpectator($login, 1);
             }
         } */
    }


    /**
     * return count of remaining players
     * @return int
     */
    public function calcRemaining()
    {
        $playerRemaining = 0;
        foreach ($this->cupPlayers as $login => $player) {
            if ($player->isPlaying == true) {
                $playerRemaining++;
            }
        }
        return $playerRemaining;
    }

    /**
     * @return int
     */
    public function calcRo()
    {
        $playerRemaining = $this->calcRemaining();

        if ($playerRemaining > 16) {
            return 32;
        } elseif ($playerRemaining > 8 && $playerRemaining <= 16) {
            return 16;
        } elseif ($playerRemaining > 4 && $playerRemaining <= 8) {
            return 8;
        } else {
            return 4;
        }

    }

    /**
     * sets the next phase
     */
    public function nextPhase()
    {
        $playerRemaining = $this->calcRemaining();
        $this->d("remaining: " . $playerRemaining, "process:" . $this->calcRo());

        if ($playerRemaining > 32) {
            $this->processPhase(32);
        } elseif ($playerRemaining > 16 && $playerRemaining <= 32) {
            $this->processPhase(16);
        } elseif ($playerRemaining > 8 && $playerRemaining <= 16) {
            $this->processPhase(8);
        } elseif ($playerRemaining > 4 && $playerRemaining <= 8) {
            $this->processPhase(4);
            $this->setupRules();
        }
    }

    /**
     * sets players to spectate
     *
     * @param $howManyToKeep
     */
    public function processPhase($howManyToKeep)
    {
        $this->d("Keeping top " . $howManyToKeep . " players!", __FUNCTION__);

        $index = 0;
        ArrayOfObj::asortAsc($this->cupPlayers, "scores");

        foreach ($this->cupPlayers as $login => $score) {
            if ($this->cupPlayers[$login]->isPlaying) $index++;
            $this->cupPlayers[$login]->scores = -1;

            if ($howManyToKeep < $index) {
                try {
                    $this->connection->forceSpectator($login, 1);
                    $this->cupPlayers[$login]->isPlaying = false;
                } catch (\Exception $ex) {
                    $this->d("failed to set specator for " . $login . " reason: " . $ex->getMessage());
                }
            }
        }

        $this->mapTopScores = array();
        $this->Scoretable();
    }

    /**
     * set all players to play
     */
    public function releaseSpec()
    {
        foreach ($this->storage->spectators as $login => $player) {
            if ($player->forceSpectator == 1) {
                try {
                    $this->connection->forceSpectator($login, 2);
                    $this->connection->forceSpectator($login, 0);
                } catch (\Exception $ex) {
                    $this->d("error while forcing spectate" . $ex->getMessage());
                }
            }
        }
    }

    /**
     * set all players to spectate
     */
    public function forceSpecAll()
    {
        foreach ($this->storage->players as $login => $player) {
            try {
                $this->connection->forceSpectator($login, 1);
            } catch (\Exception $ex) {
                $this->d("error while forcing spectate" . $ex->getMessage());
            }
        }
    }

#region UI

    /**
     * set custom UI to hide
     */
    public function hideUI()
    {
        CustomUI::HideForAll(CustomUI::SCORETABLE);
        CustomUI::HideForAll(CustomUI::ROUND_SCORES);
    }

    /**
     * set custom UI to show
     */
    public function showUI()
    {
        CustomUI::ShowForAll(CustomUI::SCORETABLE);
        // CustomUI::ShowForAll(CustomUI::ROUND_SCORES);
    }

    /**
     *
     */
    public function showStatusWidget()
    {
        /** @var CupInfo $info */
        $info = CupInfo::Create(null);

        if ($this->practiceMode == true) {
            $this->checkPractise();
            $info->show();
            return;
        }

        if ($this->enabled == false) return;

        if ($this->connection->getWarmUp()) {
            $info->setText('$f90Warmup');
        } else {
            $info->setText('$0f0LIVE - ro' . $this->calcRo() . " m" . ($this->mapNumber + 1) . " r" . ($this->roundNumber + 1));
        }

        $this->d("ro:" . $this->calcRo() . " remaining: " . $this->calcRemaining() . " mapNumber:" . $this->mapNumber . " roundNumber: " . $this->roundNumber . " RoundInap:" . $this->roundInMap);
        $rounds = $this->connection->getCupRoundsPerMap();
        $dur = $this->connection->getCupWarmUpDuration();
        $this->d("roundsPerMap:" . $rounds['CurrentValue'] . " warmup:" . $dur['CurrentValue']);

        $info->show();
    }

    /**
     *
     */
    public function Scoretable()
    {
        Scoretable::EraseAll();

        if ($this->enabled == false) return;
        $this->updateScores();

        /** @var Scoretable $win */
        $win = Scoretable::Create(null);
        $win->setData($this->cupPlayers);
        $win->setLayer($this->scoretableLayer);
        $win->setPosZ(180);
        $win->centerOnScreen();
        $win->show();

    }
    #endregion

    #region Debug
    /**
     * debugtool: generate dummy times
     */
    public function genTimes()
    {
        foreach ($this->cupPlayers as $login => $player) {
            $this->cupPlayers[$login]->scores = mt_rand(1000, 100000);
        }
        $this->Scoretable();
    }

    /**
     * debugtool: set specate number of player
     * @param int $number
     */
    public function setSpectate($number = 0)
    {
        $players = array();
        foreach ($this->cupPlayers as $player) {
            if ($player->isConnected) {
                $players[] = $player;
            }
        }

        for ($x = 0; $x < $number; $x++) {
            $player = $players[mt_rand(0, (count($players) - 1))];
            $login = $player->login;
            $this->cupPlayers[$login]->isConnected = false;
            $this->cupPlayers[$login]->isPlaying = false;
            $this->connection->forceSpectator($login, 1);
        }

        $this->Scoretable();
    }

    /**
     * @param string $msg
     * @param string $func
     */
    public function d($msg, $func = "debug")
    {
        $ag = AdminGroups::getInstance();
        $ag->announceToPermission(Permission::CHAT_ADMINCHAT, "[" . $func . "] " . $msg);

        Console::out("[" . $func . "] " . $msg . "\n");
    }
    #endregion

#endregion
}
