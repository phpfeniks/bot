<?php
namespace Feniks\Bot;

use Feniks\Bot\Models\Channel;
use Feniks\Bot\Models\Guild;
use Feniks\Bot\Models\Season;
use Feniks\Bot\Models\User;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class Handler
{

    public static function seen(Message $message)
    {
        $user = User::updateOrCreate([
            'discord_id' => $message->author->id,
        ], [
            'name' => $message->author->displayname,
            'avatar' => $message->author->avatar,
        ]);
        $guild = Guild::where('discord_id', $message->guild_id)->first();
        $guild->users()->syncWithoutDetaching([$user->id => ['username' => $message->member?->nick]]);
    }

    public static function message(Message $message , Discord $discord)
    {
        if($message->author->bot === true) {
            return;
        }

        $user = User::where('discord_id', $message->author->id)->first();
        $channel = Channel::where('discord_id', $message->channel->id)->first();
        $guild = Guild::where('discord_id', $message->guild_id)->first();

        $messageLog = new \Feniks\Bot\Models\Message();
        $messageLog->discord_id = $message->id;
        $messageLog->guild_id = $guild->id;
        $messageLog->channel_id = $channel->id;
        $messageLog->user_id = $user->id;
        $messageLog->length = strlen($message->content);

        $pointsFactor = $guild->settings()->get('points.global.factor', 10);

        $overrides = $guild->settings()->get('points.overrides', []);
        foreach ($overrides as $override) {
            if($override['channel'] == $channel->id)  {
                if(is_numeric($override['factor'])) {
                    $pointsFactor = $override['factor'];
                }
                break;
            }
        }



        $flood = Cache::get('user.'.$user->id.'.flood', 1);
        $lastMessage = Cache::get('user.'.$user->id.'.lastMessage', 0);

        $messageLen = strlen($message->content);
        if($messageLen <= 0) {
            $messageLen = 0.05;
        }

        $timeSinceLastMessage = microtime(true)-$lastMessage;
        if($timeSinceLastMessage <= 0) {
            $timeSinceLastMessage = 0.05;
        }

        $timeMultiplyer = 1/($timeSinceLastMessage*10);
        $lenMulitplyer = 1/($messageLen*10);

        $flood = $flood-($timeMultiplyer+$lenMulitplyer);

        if($flood < 0) {
            $flood=0;
        }

        Cache::put('user.'.$user->id.'.lastMessage', time(), 3600);
        Cache::put('user.'.$user->id.'.flood', $flood, 600);

        $points = ceil($pointsFactor*$messageLen*$flood);

        $seasons = $guild->seasons()->whereDate('start_date', '<=', Carbon::now('UTC'))->whereDate('end_date', '>=', Carbon::now('UTC'))->get();
        foreach($seasons as $season) {
            $season->users()->syncWithoutDetaching([$user->id]);
            $userseason = $user->seasons()->where('seasons.id', $season->id)->first();

            $userseason->pivot->points = $userseason->pivot->points + $points;
            $userseason->pivot->save();
        }


        foreach($user->guilds as $guild) {
            if($guild->discord_id !== $message->guild_id) {
                continue;
            }
            $ranks = $guild->settings()->get('points.ranks', []);

            $currentScore = $guild->pivot->points;
            $newScore = $guild->pivot->points + $points;
            $currentRank = -1;
            $newRank = null;
            $nextRank = null;

            foreach($ranks as $rankId => $rank) {
                //dump([$rankId, $currentScore, (int) $rank['requirement']]);
                if($currentScore >= (int) $rank['requirement']) {
                    $currentRank = $rankId;
                }
                if($newScore >= (int) $rank['requirement']) {
                    $newRank = $rankId;
                }
            }


            //dump([$newRank, $currentRank]);

            if($newRank !== null && $newRank !== $currentRank) {
                echo "{$message->member->nick} new rank: {$newRank}", PHP_EOL;
                if(isset($ranks[$newRank+1])) {
                    $nextRank = $newRank+1;
                }

                $channel = $discord->getChannel($guild->settings()->get('general.announcement-channel', null));

                if($channel) {
                    $embed = [
                        'color' => '#FEE75C',
                        'author' => [
                            'name' => $guild->name,
                            'icon_url' => $guild->avatar
                        ],
                        "title" => ":sparkles: DING! :sparkles: ",
                        "description" => "<@{$user->discord_id}>, you have reached level {$newRank} :tada:",
                        'fields' =>array(
                            '0' => array(
                                'name' => ':arrow_forward: Current score',
                                'value' => "`{$newScore} XP`",
                                'inline' => true
                            ),
                            '1' => array(
                                'name' => ":arrow_up:  Next level",
                                'value' => $nextRank ? "`{$ranks[$nextRank]['requirement']} XP`" : '-',
                                'inline' => true
                            ),
                        ),
                        'footer' => array(
                            'icon_url'  => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
                            'text'  => 'Powered by Feniks',
                        ),
                    ];
                    $embed = new Embed($discord, $embed);
                    $reply = MessageBuilder::new()
                        ->addEmbed($embed)
                        ->setTts(true);

                    $channel->sendMessage($reply)->done(function (Message $reply) {
                        // ...
                    });
                }


                if ($ranks[$newRank]['role'] != 0) {
                    foreach ($ranks as $rankId => $rank) {
                        if (isset($ranks[$rankId]['role']) && $ranks[$rankId]['role'] != 0) {
                            try {
                                $message->member->removeRole($ranks[$rankId]['role'])->done(function () {

                                });
                            } catch (\RuntimeException $e) {
                                // I don't care
                            }
                        }

                    }
                    if (isset($ranks[$newRank]['role']) && $ranks[$newRank]['role'] != 0) {
                        $message->member->addRole($ranks[$newRank]['role'])->done(function () {

                        });
                    }
                }
            }
            $guild->pivot->points = $newScore;
            $guild->pivot->save();
        }


        $messageLog->points = $points;
        $messageLog->save();


        echo "{$message->member->nick} awarded {$points} points", PHP_EOL;
    }
}
