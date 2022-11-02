<?php


namespace Feniks\Bot\User;


use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Feniks\Bot\Models\Guild as GuildModel;
use Feniks\Bot\Models\User;

class Level
{
    private $guild;
    private $discord;
    private $user;

    public function __construct(Interaction $interaction, Discord $discord)
    {
        $this->guild =  GuildModel::where('discord_id', $interaction->guild_id)->first();
        $this->user = User::where('discord_id', $interaction->user->id)->first();
        $this->discord = $discord;
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

    public function ranks()
    {
        return $this->guild->settings()->get('points.ranks', []);
    }

    public function showLevel()
    {
        $guild = GuildModel::where('discord_id', $this->guild->id)->first();
        $userGuild = $this->user->guilds()->where('guilds.id', $this->guild->id)->first();

        $embed = new \Feniks\Bot\Embed($this->guild);
        $embed
            ->title(":clipboard: Your level")
            ->description("Below is a summary of your level and points")
            ->field('Total XP', $userGuild->pivot->points, true)
            ->field('Current level', $this->level($userGuild->pivot->points), true)
        ;


        return new Embed($this->discord, $embed->toArray());
    }
}