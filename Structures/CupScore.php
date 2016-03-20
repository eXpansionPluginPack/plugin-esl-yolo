<?php

namespace ManiaLivePlugins\ESL\YOLOcup\Structures;

/**
 * Description of CupScore
 *
 * @author Reaby
 */
class CupScore extends \Maniaplanet\DedicatedServer\Structures\AbstractStructure
{
    /** @var string */
    public $login, $nickName;

    /** @var integer */
    public $place = -1;

    /** @var boolean */
    public $isPlaying = true;

    public $isConnected = true;
    
    public $scores = -1;
    
    public function __construct(\ManiaLive\Data\Player $player)
    {
        $this->playerId = $player->playerId;
        $this->login    = $player->login;
        $this->nickName = $player->nickName;
    }
}