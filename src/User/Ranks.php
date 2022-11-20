<?php


namespace Feniks\Bot\User;

use Feniks\Bot\Models\Guild;

trait Ranks
{
    public function level($points)
    {
        $currentRank = 0;
        foreach($this->ranks() as $rankId => $rank) {
            if($points >= (int) $rank['requirement']) {
                $currentRank = $rankId;
            }

        }

        return $currentRank;
    }

    public function levelRequirement($level)
    {
        if(isset($this->ranks()[$level]['requirement'])) {
            return $this->ranks()[$level]['requirement'];
        }

        return false;
    }

    public function ranks()
    {
        return $this->guild->settings()->get('points.ranks', []);
    }
}