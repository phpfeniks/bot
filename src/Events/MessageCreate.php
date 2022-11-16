<?php


namespace Feniks\Bot\Events;


use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Feniks\Bot\Models\Channel;
use Feniks\Bot\Models\Guild;
use Feniks\Bot\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class MessageCreate
{

    protected $args = [
        [
            'type' => Message::class,
            'var' => 'message'
        ],
        [
            'type' => Discord::class,
            'var' => 'discord'
        ]
    ];

    protected $message ;
    protected $discord;

    public function __construct(Array $args)
    {
        foreach($args as $index => $argument) {
            if(! isset($this->args[$index])) {
                break;
            }
            if($argument instanceof $this->args[$index]['type']) {
                $this->{$this->args[$index]['var']} = $argument;
            }
        }
    }

    public function handle()
    {
        $this->seen($this->message);
        $this->message($this->message, $this->discord);
    }

    public static function seen(Message $message)
    {
        $user = User::updateOrCreate([
            'discord_id' => $message->author->id,
        ], [
            'name' => '',
            'avatar' => '',
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
        $guild = Guild::where('discord_id', $message->guild_id)->first();
        $channel = Channel::updateOrCreate([
            'discord_id' => $message->channel->id,
        ], [
            'guild_id' => $guild->id,
            'name' => $message->channel->name,
            'type' => $message->channel->type,
        ]);


        $messageLog = new \Feniks\Bot\Models\Message();
        $messageLog->discord_id = $message->id;
        $messageLog->guild_id = $guild->id;
        $messageLog->channel_id = $channel->id;
        $messageLog->user_id = $user->id;
        $messageLog->length = strlen($message->content);

        $points = $guild->settings()->get('points.global.factor', 10);
        $factor = 1;
        $words = str_word_count($message->content);

        $overrides = $guild->settings()->get('points.overrides', []);
        foreach ($overrides as $override) {
            if($override['channel'] == $channel->id)  {
                if(is_numeric($override['factor'])) {
                    $factor = $override['factor'];
                }
                break;
            }
        }

        $flood = Cache::get('user.'.$user->id.'.flood', 1);
        $lastMessage = Cache::get('user.'.$user->id.'.lastMessage', 0);

        $timeSinceLastMessage = microtime(true)-$lastMessage;
        if($timeSinceLastMessage <= 0) {
            $timeSinceLastMessage = 0.05;
        }

        $timeMultiplyer = 1/($timeSinceLastMessage*10);

        $flood = $flood-$timeMultiplyer;

        if($flood < 0) {
            $flood=0;
        }

        Cache::put('user.'.$user->id.'.lastMessage', time(), 3600);
        Cache::put('user.'.$user->id.'.flood', $flood, 60);

        // Add length bonus
        $lenBonusReq = $guild->settings()->get('points.lengthMultiplier.words', null);
        $logBonuses = [];

        if($lenBonusReq) {
            $bonus = ($points/100)*$guild->settings()->get('points.lengthMultiplier.bonus', 0);
            $lenBonus = ($bonus*floor($words/$lenBonusReq));
            $logBonuses['lenBonus'] = $lenBonus;
            $points = $points + $lenBonus;
        }

        // add fixed bonuses
        $bonuses = $guild->settings()->get('points.bonuses', []);
        $flatBonus = 0;
        foreach ($bonuses as $bonus) {
            if($words < $bonus['words']) {
                continue;
            }
            $flatBonus = $bonus['bonus'];
            $logBonuses['flatBonus'] = $flatBonus;
        }
        $points = $points + $flatBonus;

        $discord->getLogger()->debug('Awarded points', [
            'message_id' => $message->id,
            'guild_id' => $guild->id,
            'points' => $points,
            'bonuses' => $logBonuses,
            'factor' => $factor,
            'flood' => $flood,
        ]);

        $points = ceil($points*$factor*$flood);
        if($points > 4294967295) { // Max for int MySQL field. Should be enough.
            $points = 4294967295;
        }


        // finished calculating points

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
                if (isset($ranks[$newRank + 1])) {
                    $nextRank = $newRank + 1;
                }

                $channel = $discord->getChannel($guild->settings()->get('general.announcement-channel', null));

                if ($channel) {
                    $annRank = $newRank+1;
                    $levelUpMessage = "You have reached level {$annRank} :tada:";
                    if(isset($ranks[$newRank]['message']) && trim($ranks[$newRank]['message']) !== '') {
                        $levelUpMessage = $ranks[$newRank]['message'];
                    }

                    $embed = [
                        'color' => '#FEE75C',
                        'author' => [
                            'name' => $guild->name,
                            'icon_url' => $guild->avatar
                        ],
                        "title" => ":sparkles: DING! :sparkles:",
                        "description" => "<@{$user->discord_id}>! {$levelUpMessage}",
                        'fields' => array(
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
                            'icon_url' => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
                            'text' => 'Powered by Feniks',
                        ),
                    ];
                    $embed = new Embed($discord, $embed);
                    $reply = MessageBuilder::new()
                        ->addEmbed($embed);

                    if ($channel->getBotPermissions()->view_channel && $channel->getBotPermissions()->send_messages && $channel->getBotPermissions()->embed_links) {
                        $channel->sendMessage($reply)
                            ->otherwise(function () use ($guild, $discord) {
                                $discord->getLogger()->warning('Unable to announce level up ', ['guild_id' => $guild->discord_id]);
                            })
                            ->done(function (Message $reply) {

                            }
                            );
                    } else {
                        $discord->getLogger()->warning('No access to announcement channel for guild ' . $guild->discord_id);
                    }

                }

                if ($message->channel->getBotPermissions()->manage_roles === true || $message->channel->getBotPermissions()->administrator) {
                    if ($ranks[$newRank]['role'] != 0) {
                        foreach ($ranks as $rankId => $rank) {
                            if (isset($ranks[$rankId]['role']) && $ranks[$rankId]['role'] != 0 && $rankId !== $newRank) {
                                $message->member->removeRole($ranks[$rankId]['role'])
                                    ->otherwise(function() use ($discord, $guild) {
                                        $discord->getLogger()->info('Unable to remove old role', [
                                            'guild_id' => $guild->discord_id
                                        ]);
                                    })
                                    ->done(function () {

                                    });
                            }
                        }
                        if (isset($ranks[$newRank]['role']) && $ranks[$newRank]['role'] != 0) {
                            $message->member->addRole($ranks[$newRank]['role'])
                                ->otherwise(function() use ($discord, $guild) {
                                    $discord->getLogger()->warning('Unable to assign role', [
                                        'guild_id' => $guild->discord_id
                                    ]);
                                })
                                ->done(function () use ($discord, $guild) {
                                    $discord->getLogger()->info('Assigned new role', [
                                        'guild_id' => $guild->discord_id
                                    ]);
                                });
                        }
                    }
                } else {
                    $discord->getLogger()->warning('Missing permission to assign roles', [
                        'guild_id' => $guild->discord_id
                    ]);
                }
            }
            $guild->pivot->points = $newScore;
            $guild->pivot->save();
        }


        $messageLog->points = $points;
        $messageLog->save();
    }

}