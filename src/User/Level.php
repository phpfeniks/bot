<?php


namespace Feniks\Bot\User;


use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Feniks\Bot\Models\Guild as GuildModel;
use Feniks\Bot\Models\User;
use Illuminate\Support\Carbon;

class Level
{
    private $guild;
    private $discord;
    private $user;
    private $interaction;

    public function __construct(Interaction $interaction, Discord $discord)
    {
        $this->guild =  GuildModel::where('discord_id', $interaction->guild_id)->first();

        $this->user = User::where('discord_id', $interaction->user->id)->first();
        if($interaction->data->options['user'] !== null) {
            $this->user = User::where('discord_id', $interaction->data->options['user']->value)->first();
        }

        $this->discord = $discord;
        $this->interaction = $interaction;
    }

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

    public function showLevel()
    {
        dump($this->user);
        if(! $this->user) {
            return false;
        }

        $userGuild = $this->user->guilds()->where('guilds.id', $this->guild->id)->first();
        if(! $userGuild) {
            return false;
        }

        $requirment['current'] = $this->levelRequirement($this->level($userGuild->pivot->points));
        $requirment['next'] = $this->levelRequirement($this->level($userGuild->pivot->points)+1);
        $requirment['progress'] = false;


        $embed = new \Feniks\Bot\Embed($this->guild);
        $embed
            ->title($this->interaction->member->nick ? ':sparkles: '.$this->interaction->member->nick.' ('.$this->interaction->user->displayname.')' : ':sparkles: '.$this->interaction->user->displayname )
            ->description("Below is a summary of your level and points")
            ->field(':chart_with_upwards_trend: Total XP', $userGuild->pivot->points, true)
            ->field(':trophy: Current level', $this->level($userGuild->pivot->points), true)
        ;


        $embed->thumbnail($this->interaction->user->avatar);


        if($requirment['next'] !== false) {
            $xpForLevel = $requirment['next'] - $requirment['current'];
            $xpAfterLevelUp = $userGuild->pivot->points - $requirment['current'];
            $precent = round(($xpAfterLevelUp / $xpForLevel) * 100);
            $progress = "`{$xpAfterLevelUp}/{$xpForLevel} - {$precent}%`";
            $embed->field(
                ':shield: Progress to next level',
                $progress
            );
        }

        $seasons = $this->guild->seasons()
            ->orderBy('end_date', 'desc')
            ->whereDate('start_date', '<=', Carbon::now('UTC'))
            ->whereDate('end_date', '>=', Carbon::now('UTC')->subWeek())
            ->get();

        foreach($seasons as $season) {
            $userseason = $this->user->seasons()->where('seasons.id', $season->id)->first();

            $embed->field(
                ":ticket: Season: *{$season->name}*",
                "Total `{$userseason->pivot->points} XP`",
                true
            );

        }


        return new Embed($this->discord, $embed->toArray());
    }
}