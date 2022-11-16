<?php


namespace Feniks\Bot\User;


use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Member;
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


        $this->interaction->guild->members->fetch($this->user->discord_id)
            ->otherwise(function () {

            })
            ->done(function (Member $member) use ($embed, $requirment, $userGuild) {
                $embed->title($member->nick ? ':sparkles: '.$member->nick.' ('.$member->user->username.')' : ':sparkles: '.$member->user->username );
                $embed->thumbnail($member->avatar ? $member->avatar : $member->user->avatar);

                $embed
                    ->description("Below is a summary of your level and points")
                    ->field(':chart_with_upwards_trend: Total XP', $userGuild->pivot->points, true)
                    ->field(':trophy: Current level', $this->level($userGuild->pivot->points), true)
                ;


                if($requirment['next'] !== false) {
                    $xpForLevel = $requirment['next'] - $requirment['current'];
                    $xpAfterLevelUp = $userGuild->pivot->points > 0 ? $userGuild->pivot->points - $requirment['current'] : 0;
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

                    if ($userseason) {
                        $embed->field(
                            ":ticket: Season: *{$season->name}*",
                            "Total `{$userseason->pivot->points} XP`",
                            false
                        );
                    }
                }

                if(! $this->guild->isConfigured()) {
                    $embed->field(
                        ":robot: Missing configuration",
                        ":x: **Basic configuration for Feniks is missing.** You should tell your server administrator to [configure Feniks](https://docs.feniksbot.com/#/admin/quickstart)."
                    );
                }

                dump($embed->toArray());
                $showLevel =  new Embed($this->discord, $embed->toArray());
                $this->interaction->respondWithMessage(MessageBuilder::new()->addEmbed($showLevel));
            });

        return true;
    }
}