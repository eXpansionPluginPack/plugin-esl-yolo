<?php

namespace ManiaLivePlugins\ESL\YOLOcup\Gui\Widgets;

/**
 * Description of PraciseInfo
 *
 * @author Petri Järvisalo <petri.jarvisalo@gmail.com>
 */
class CupInfo extends \ManiaLivePlugins\eXpansion\Gui\Widgets\PlainWidget
{
    protected $frame, $status;

    protected function onConstruct()
    {
        parent::onConstruct();
        $this->setName("YOLOcup: Info");
        $this->setPosition(120, 0);

        $this->frame = new \ManiaLive\Gui\Controls\Frame(0, 0, new \ManiaLib\Gui\Layouts\Column());
        $this->addComponent($this->frame);

        $label = new \ManiaLib\Gui\Elements\Label(50, 8);
        $label->setTextSize(2);
        $label->setTextEmboss();
        $label->setStyle('TextRaceMessageBig');
        $label->setText('$fffESL: YOLO cup');
        $label->setAlign("center", "top");
        $this->frame->addComponent($label);

        $this->status = new \ManiaLib\Gui\Elements\Label(50, 8);
        $this->status->setTextSize(3);
        $this->status->setStyle('TextRaceMessageBig');
        $this->status->setTextEmboss();
        $this->status->setText('$f90Practice');
        $this->status->setAlign("center", "top");
        $this->frame->addComponent($this->status);
    }

    public function setText($text)
    {
        $this->status->setText($text);
    }
}