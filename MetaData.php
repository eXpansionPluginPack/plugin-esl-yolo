<?php

namespace ManiaLivePlugins\ESL\YOLOcup;

use ManiaLivePlugins\eXpansion\Core\types\config\types\Boolean;
use ManiaLivePlugins\eXpansion\Core\types\config\types\TypeString;

/**
 * Description of MetaData
 *
 * @author Petri
 */
class MetaData extends \ManiaLivePlugins\eXpansion\Core\types\config\MetaData
{

	public function onBeginLoad()
	{
            
		$this->setName("ESL: YOLOcup");
		$this->setDescription("provides YOLOcup gamemode");
		$this->setGroups(array('ESL'));

                $this->addTitleSupport("TM");
		$this->addTitleSupport("Trackmania");
                $this->setEnviAsTitle(true);
                $this->addGameModeCompability(\Maniaplanet\DedicatedServer\Structures\GameInfos::GAMEMODE_TIMEATTACK);
                $this->addGameModeCompability(\Maniaplanet\DedicatedServer\Structures\GameInfos::GAMEMODE_CUP);
		$this->setScriptCompatibilityMode(false);

		$config = Config::getInstance();

                $var = New \ManiaLivePlugins\eXpansion\Core\types\config\types\TypeFloat("atMultiplier", "Author time multiplier for DNF", $config, false, false);
                $var->setDefaultValue(5.0);
                $this->registerVariable($var);


	}

}
