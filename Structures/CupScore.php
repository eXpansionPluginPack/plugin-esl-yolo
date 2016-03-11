<?php

namespace ManiaLivePlugins\ESL\YOLOcup\Structures;
use Maniaplanet\DedicatedServer\Structures\AbstractStructure;

/**
 * Description of CupScore
 *
 * @author Reaby
 */
class CupScore extends AbstractStructure
{
    /** @var string Player information */
    public $login, $nickName;

    /** @var integer Current position */
    public $place = -1;

    /** @var boolean Check if the current player is playing */
    public $isPlaying = true;

    /** @var bool Is the player connected to the server */
    public $isConnected = true;

    /** @var int Current score */
    public $scores = -1;
    
    public function __construct(\ManiaLive\Data\Player $player)
    {
        $this->playerId = $player->playerId;
        $this->login    = $player->login;
        $this->nickName = $player->nickName;
    }
}