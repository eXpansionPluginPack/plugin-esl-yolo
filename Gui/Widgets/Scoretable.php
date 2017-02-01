<?php

namespace ManiaLivePlugins\ESL\YOLOcup\Gui\Widgets;

/**
 * Description of Scoretable
 *
 * @author Reaby
 */
class Scoretable extends \ManiaLivePlugins\eXpansion\Gui\Windows\PlainWindow
{
    protected $background, $rankingslabel, $pointslimit, $gamemode, $next, $prev;
    protected $frame;
    protected $page        = 0;
    protected $itemsOnPage = 16;

    /** @var \ManiaLivePlugins\ESL\YOLOcup\Structures\CupScore[] */
    protected $scores = array();
    protected $actionNext;
    protected $actionPrev;

    protected function onConstruct()
    {
        $this->sizeX = 165;
        $this->sizeY = 90;

        $this->actionNext = $this->createAction(array($this, "next"));
        $this->actionPrev = $this->createAction(array($this, "prev"));

        $this->background = new \ManiaLib\Gui\Elements\Quad($this->sizeX, $this->sizeY);
        $this->background->setStyle("Bgs1InRace");
        $this->background->setSubStyle("BgList");
        $this->addComponent($this->background);
        $this->background->setPosY(4);

        $this->rankingslabel = new \ManiaLib\Gui\Elements\Label(120, 6);
        $this->rankingslabel->setText(__("Score Rankings"));
        $this->rankingslabel->setTextColor("fff");
        $this->rankingslabel->setTextSize(4);
        $this->rankingslabel->setPosition($this->sizeX / 2, 3);
        $this->rankingslabel->setAlign("center");
        $this->addComponent($this->rankingslabel);

        $this->pointslimit = new \ManiaLib\Gui\Elements\Label(30, 6);
        $this->pointslimit->setTextColor("fff");
        $this->pointslimit->setTextSize(1);
        $this->pointslimit->setPosition($this->sizeX / 2, -$this->sizeY);
        $this->pointslimit->setAlign("center");
        $this->addComponent($this->pointslimit);

        $this->gamemode = new \ManiaLib\Gui\Elements\Label(30, 6);
        $this->gamemode->setTextColor("fff");
        $this->gamemode->setTextSize(1);
        $this->gamemode->setText('$sGame Mode: YOLO cup');
        $this->gamemode->setPosition($this->sizeX / 2, -$this->sizeY - 4);
        $this->gamemode->setAlign("center");
        $this->addComponent($this->gamemode);

        $this->next = new \ManiaLib\Gui\Elements\Quad(8, 8);
        $this->next->setAction($this->actionNext);
        $this->next->setStyle("Icons64x64_1");
        $this->next->setSubStyle("ArrowNext");
        $this->next->setPosition($this->sizeX - 9, 5);
        $this->addComponent($this->next);

        $this->prev = new \ManiaLib\Gui\Elements\Quad(8, 8);
        $this->prev->setAction($this->actionPrev);
        $this->prev->setStyle("Icons64x64_1");
        $this->prev->setSubStyle("ArrowPrev");
        $this->prev->setPosition($this->sizeX - 16, 5);
        $this->addComponent($this->prev);

        $this->frame = new \ManiaLive\Gui\Controls\Frame();
        $this->frame->setLayout(new \ManiaLib\Gui\Layouts\VerticalFlow());
        $this->frame->setSize(120, 80);
        $this->frame->setPosition(2, -3);
        $this->addComponent($this->frame);
    }

    public function next($login)
    {

        $newstart = ($this->page + 1) * $this->itemsOnPage;
        if ($newstart < count($this->scores)) {
            $this->page++;
        }

        $this->redraw($login);
    }

    public function prev($login)
    {
        $this->page--;
        if ($this->page < 0) $this->page = 0;

        $this->redraw($login);
    }

    protected function onDraw()
    {
        parent::onDraw();

        $this->frame->destroyComponents();

        $this->next->setHidden(false);
        $this->prev->setHidden(false);

        $newstart = ($this->page + 1) * $this->itemsOnPage;
        if ($newstart > count($this->scores)) {
            $this->next->setHidden(true);
        }

        if ($this->page == 0) {
            $this->prev->setHidden(true);
        }

        $this->pointslimit->setText("");


        $items = array();

        $x = 0;
        // first iterate for players who are actually driving
        foreach ($this->scores as $scoreitem) {
            if ($scoreitem->isPlaying && $scoreitem->scores > 0) {
                $items[] = new \ManiaLivePlugins\ESL\YOLOcup\Gui\Controls\CupScoreTableItem($x, $scoreitem, -1);
                $x++;
            }
        }

        foreach ($this->scores as $scoreitem) {
            if ($scoreitem->isPlaying && $scoreitem->scores <= 0) {
                $items[] = new \ManiaLivePlugins\ESL\YOLOcup\Gui\Controls\CupScoreTableItem($x, $scoreitem, -1);
                $x++;
           }
        }

        // add non-playing players bottom
        foreach ($this->scores as $scoreitem) {
            if ($scoreitem->isPlaying == false) {
                $items[] = new \ManiaLivePlugins\ESL\YOLOcup\Gui\Controls\CupScoreTableItem($x, $scoreitem, -1);
                $x++;
            }
        }

        $x = 0;
        foreach ($items as $component) {
            $start = $this->page * $this->itemsOnPage;
            $limit = $start + $this->itemsOnPage;
            if ($x >= $start && $x < $limit) {
                $this->frame->addComponent($component);
            }
            $x++;
        }

    }

    public function setData($scores)
    {
        $this->scores = $scores;
    }
}
