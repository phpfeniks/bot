<?php


namespace Feniks\Bot\Events;


use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Feniks\Bot\Models\Channel;
use Feniks\Bot\Models\Guild;
use Feniks\Bot\Models\User;
use Feniks\Bot\User\Progress;
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
        if(! $guild) {
            return;
        }
        $guild->users()->syncWithoutDetaching([$user->id => []]);
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

        Cache::put('message-'.$message->id.'-author', $message->author->id, 3600);

        $progress = new Progress($discord, $message->guild, $message->member);

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


        if($lenBonusReq) {
            $bonus = ($points/100)*$guild->settings()->get('points.lengthMultiplier.bonus', 0);
            $lenBonus = ($bonus*floor($words/$lenBonusReq));
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
        }
        $points = $points + $flatBonus;


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
            $progress->levelUp($guild, $user, $points);
        }
    }
}