<?php

namespace ManiaLivePlugins\ESL\YOLOcup;

use Exception;
use ManiaLive\Data\Player;
use ManiaLive\Gui\CustomUI;
use ManiaLivePlugins\ESL\YOLOcup\Gui\Widgets\CupInfo;
use ManiaLivePlugins\ESL\YOLOcup\Gui\Widgets\Scoretable;
use ManiaLivePlugins\ESL\YOLOcup\Structures\CupScore;
use ManiaLivePlugins\eXpansion\AdminGroups\AdminGroups;
use ManiaLivePlugins\eXpansion\Core\types\ExpPlugin;
use ManiaLivePlugins\eXpansion\Helpers\ArrayOfObj;
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

    private $roundInMap = -1;

    /** @var bool */
    private $endYOLOcup = false;


    /**
     * onReady
     */
    public function eXpOnReady()
    {
        $this->registerChatCommand("t", "nextPhase", 0, false, AdminGroups::get());
        $this->registerChatCommand("d", "disconnect", 1, true, AdminGroups::get());
        $this->registerChatCommand("g", "gentimes", 0, false, AdminGroups::get());

//$this->registerChatCommand("specrel", "releaseSpec", 0, false);

        $admingroup = AdminGroups::getInstance();
        $cmd = AdminGroups::addAdminCommand('game yolo', $this, 'chat_yolocup', 'game_settings');
        $admingroup->addShortAlias($cmd, 'yolo');

        $this->enableDedicatedEvents();
    }

    function gentimes()
    {
        foreach ($this->cupPlayers as $login => $player) {
            $this->cupPlayers[$login]->scores = mt_rand(1000, 100000);
        }
        echo "generated times";
        $this->Scoretable();
    }

    function disconnect($login, $number = 0)
    {
        echo "disconnect";
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
            echo "disconnecting :" . $login . "\n";
        }

        $this->Scoretable();
    }

    function chat_yolocup($fromLogin, $params)
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
                    $this->enabled = true;
                    $this->justStarted = true;
                    $this->startingMapUid = $this->storage->nextMap->uId;
                    $this->connection->setGameMode(GameInfos::GAMEMODE_CUP);
                    $this->connection->setCupPointsLimit(1000);
                    $this->hideUI();
                    $this->releaseSpec();
                    $this->registerPlayers();
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
        $this->lastRoundWinner = "";
        $this->endYOLOcup = false;
    }

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
     * contains checks made for practice mode
     *
     * @return null
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

    public function onPlayerDisconnect($login, $disconnectionReason = null)
    {
        if ($this->enabled == false) return;

        if (array_key_exists($login, $this->cupPlayers)) {
            $this->cupPlayers[$login]->isConnected = false;
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
     * @return null
     */
    public function onPlayerFinish($playerUid, $login, $timeOrScore)
    {
        if ($this->enabled == false || $this->practiceMode == true) return;
        if ($timeOrScore == 0) return;

        $player = new Player();
        $player->playerId = $playerUid;
        $player->login = $login;
        $player->rank = count($this->roundFinish) + 1;
        $player->score = $timeOrScore;
        $this->roundFinish[] = $player;
    }

    public function showStatusWidget()
    {
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

        $this->debug("ro:" . $this->calcRo() . " remaining: " . $this->calcRemaining() . " mapNumber:" . $this->mapNumber . " roundNumber: " . $this->roundNumber . " RoundInap:" . $this->roundInMap);
        $rounds = $this->connection->getCupRoundsPerMap();
        $dur = $this->connection->getCupWarmUpDuration();

        $this->debug("roundsPerMap:" . $rounds['CurrentValue'] . " warmup:" . $dur['CurrentValue']);


        $info->show();
    }

    public function onBeginMatch()
    {
        $this->showStatusWidget();

    }

    public function onBeginRound()
    {
        $this->wasWarmup = $this->connection->getWarmUp();

        if ($this->enabled == true || $this->practiceMode == true) {
            if ($this->wasWarmup == false) {
                $this->roundNumber++;
                $this->roundInMap++;
            }

            $this->showStatusWidget();
            $this->roundFinish = array();
            $this->scoretableLayer = "scorestable";
            $this->scoreTable();
        }
    }

    /**
     *
     * @return null
     */
    public function onEndRound()
    {
        if ($this->practiceMode == true) return;
        if ($this->wasWarmup) return;
        if ($this->enabled == false) return;
        if ($this->justStarted) return;

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

        $this->scoretableLayer = "normal";
        $this->scoreTable();


// if ro == 4
        if ($this->calcRo() == 4) {
            if (array_key_exists($this->roundInMap + 1, $this->top4Players)) {
                $playerInTurn = $this->top4Players[$this->roundInMap + 1];
                echo "player:" . $playerInTurn . " index:" . ($this->roundInMap + 1) . "\n";
                $this->setPlayerToDrive($playerInTurn);
            }

            if ($this->roundNumber >= 3) {
                if ($this->storage->nextMap->uId == $this->startingMapUid) {
                    $this->endYOLOcup = true;
                }
                $this->connection->nextMap();
            }
        }
    }


    public function setPlayerToDrive($playingLogin)
    {
        print_r($this->top4Players);

        foreach ($this->top4Players as $idx => $login) {
            if ($login != $playingLogin) {
                $this->connection->forceSpectator($login, 1, true);
            }
        }

        $this->connection->executeMulticall();

        echo "set player:" . $playingLogin . " to play\n";
        $this->connection->forceSpectator($playingLogin, 2);
        $this->connection->forceSpectator($playingLogin, 0);
    }


    /** at podium */
    public function onEndMatch($rankings, $winnerTeamOrMap)
    {
        CupInfo::EraseAll();
        if ($this->practiceMode == true) return;
        if ($this->enabled == false) return;
        if ($this->wasWarmup) return;

        $ro = $this->calcRo();

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
                break;
        }

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
            if ($ro > 4) {
                $this->eXpChatSendServerMessage("[YOLOcup] End of ro " . $ro);
                $this->nextPhase();
                $this->scoretableLayer = "normal";
                $this->scoreTable();
            }

            if ($ro == 4 && $this->endYOLOcup == true) {
                $this->eXpChatSendServerMessage("YoloCup ends. Thanks for participating.");
                $this->reset();
            }
        }

    }


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
            $this->connection->setCupRoundsPerMap(8);
            $this->connection->setCupWarmUpDuration(0);

            $this->updateScores();
            foreach ($this->cupPlayers as $login => $player) {
                if ($player->isPlaying) {
                    // top4Players is now in order top 4
                    $this->top4Players[] = $login;
                }
            }

            if (count($this->top4Players) > 4) {
                $this->eXpChatSendServerMessage("[YOLOcup] Whops, more than 4 players at ro4.. reducing number to top 4");
                array_slice($this->top4Players, 0, 4, true);
            }
            // top4Players is now reversed order.. ready to cycle though rounds.
            $this->top4Players = array_reverse($this->top4Players);
        }
    }

    public function processPhase($howManyToKeep)
    {

        $this->d("Keeping top " . $howManyToKeep . " players!", __FUNCTION__);

        $index = 0;

        ArrayOfObj::asortAsc($this->cupPlayers, "scores");

        foreach ($this->cupPlayers as $login => $score) {
            if ($this->cupPlayers[$login]->isPlaying) $index++;
            $this->cupPlayers[$login]->scores = -1;

            if ($howManyToKeep < $index) {
                $this->cupPlayers[$login]->isPlaying = false;
                $this->connection->forceSpectator($login, 1);
            }
        }

        $this->mapTopScores = array();

        $this->Scoretable();
    }

    public function onEndMap($rankings, $map, $wasWarmUp, $matchContinuesOnNextMap, $restartMap)
    {
        if ($this->practiceMode == true) return;
        if ($this->enabled == false) return;
        if ($this->justStarted == true) {
            $this->justStarted = false;
            return;
        }

        $this->eXpChatSendServerMessage(eXpGetMessage('YOLOcup: $0d0 LIVE - END of map #variable#%s'), null, array($this->mapNumber));

        $this->scoretableLayer = "scorestable";
        $this->scoreTable();
    }

    public function onBeginMap($map, $warmUp, $matchContinuation)
    {
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

        if ($this->calcRo() == 4) {
            $this->setPlayerToDrive($this->top4Players[0]);
        }

        $this->scoreTable();
    }

    public function releaseSpec()
    {
        foreach ($this->storage->spectators as $login => $player) {
            if ($player->forceSpectator == 1) {
                $this->connection->forceSpectator($login, 2);
                $this->connection->forceSpectator($login, 0);
            }
        }
    }

    public function hideUI()
    {
        CustomUI::HideForAll(CustomUI::SCORETABLE);
        CustomUI::HideForAll(CustomUI::ROUND_SCORES);
    }

    public function showUI()
    {
        CustomUI::ShowForAll(CustomUI::SCORETABLE);
        CustomUI::ShowForAll(CustomUI::ROUND_SCORES);
    }

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


    public function Scoretable()
    {
        Scoretable::EraseAll();

        if ($this->enabled == false) return;

        $this->updateScores();

        $win = Scoretable::Create(null);
        $win->setData($this->cupPlayers);
        $win->setLayer($this->scoretableLayer);
        $win->setPosZ(180);
        $win->centerOnScreen();
        $win->show();
    }

    public function eXpOnUnload()
    {
        $this->showUI();
        $this->connection->setCupPointsLimit(100);
        AdminGroups::removeShortAllias("yolo");

        $ag = AdminGroups::getInstance();
        $ag->unregisterChatCommand("game yolocup");
        $this->reset();
        $this->enabled = false;
        $this->winners = array();
        $this->roundFinish = array();
        $this->lastRoundWinner = "";
        Scoretable::EraseAll();
        CupInfo::EraseAll();
        $this->disableDedicatedEvents();
    }

    public function d($msg, $func = "debug")
    {
        if (is_string($msg)) {
            $ag = AdminGroups::getInstance();
            $ag->announceToPermission(\ManiaLivePlugins\eXpansion\AdminGroups\Permission::CHAT_ADMINCHAT, "[" . $func . "]" . $msg);
        }
        $this->debug($msg);
    }
}
