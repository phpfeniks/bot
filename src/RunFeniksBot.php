<?php

namespace Feniks\Bot;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Activity;
use Feniks\Bot\Guild\Channels;
use Feniks\Bot\Guild\Guilds;
use Feniks\Bot\Guild\Roles;
use Feniks\Bot\Models\AuditLog;
use Feniks\Bot\Season\Announcer;
use Feniks\Bot\Models\User;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Feniks\Bot\Models\Channel as ChannelModel;
use Feniks\Bot\Models\Guild as GuildModel;
use Feniks\Bot\Models\Role as RoleModel;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Command\Command as SlashCommand;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;


class RunFeniksBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feniks:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $commands = [
        \Feniks\Bot\Commands\Ping::class,
        \Feniks\Bot\Commands\Help::class,
        \Feniks\Bot\Commands\Level::class,
        \Feniks\Bot\Commands\Scores::class,
        \Feniks\Bot\Commands\Season::class,
        \Feniks\Bot\Commands\Seasons::class,
        \Feniks\Bot\Commands\ManageXp::class,
    ];

    protected $events = [
        Event::MESSAGE_CREATE => \Feniks\Bot\Events\MessageCreate::class,
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $loop = Loop::get();
        $redis = (new \Clue\React\Redis\Factory($loop))->createLazyClient('localhost:6379');
        $cache = new \WyriHaximus\React\Cache\Redis($redis, 'feniks:');

        $logger = Log::channel('discord')->getLogger();
        $discord = new Discord([
            'token' => config('services.discord.bot_token'),
            'intents' => Intents::getDefaultIntents() | Intents::GUILDS | Intents::MESSAGE_CONTENT,
            'logger' => $logger,
            'loop' => $loop,
            'cacheInterface' => $cache,
        ]);

        $discord->on(Event::GUILD_CREATE, function (Guild $guild, Discord $discord) {
            $discord->getLogger()->debug('Joined guild:'. $guild['name']);
            $guilds = new Guilds();
            $guildId = $guilds->sync($guild);

            $channels = new Channels();
            $channels->sync($guild, $guildId);

            $roles = new Roles();
            $roles->sync($guild, $guildId);
        });

        $discord->on('ready', function (Discord $discord) {
            $discord->getLogger()->info('Feniks ready to fly!');
            $activity = new Activity($discord, [
                'name' => '/help',
                'type' => Activity::TYPE_LISTENING,
            ]);

            $discord->updatePresence($activity);

            $discord->getLoop()->addPeriodicTimer(3600, function($timer) use($discord) {
                $discord->getLogger()->debug('Hourly tick');

                $announcer = new Announcer($discord);
                $announcer->starting();
            });

            $discord->getLoop()->addPeriodicTimer(7200, function($timer) use($discord) {
                $discord->getLogger()->info('Updating active guilds');
                foreach($discord->guilds as $guild) {
                    $discord->getLogger()->debug('Active in guild: '.$guild->id);
                    $update = GuildModel::where('discord_id', $guild->id)->first();
                    $update->active_at = now('UTC');
                    $update->save();
                }

            });

            $this->registerCommands($discord);
            $this->registerEvents($discord);

            $discord->on(Event::CHANNEL_CREATE, function (Channel $channel, Discord $discord) {
                $guild = GuildModel::where('discord_id', $channel->guild_id)->first();
                ChannelModel::updateOrCreate([
                    'discord_id' => $channel->id,
                ], [
                    'guild_id' => $guild->id,
                    'name' => $channel->name,
                    'type' => $channel->type,
                ]);
            });

            $discord->on(Event::CHANNEL_UPDATE, function (Channel $channel, Discord $discord, ?Channel $oldChannel) {
                $guild = GuildModel::where('discord_id', $channel->guild_id)->first();
                ChannelModel::updateOrCreate([
                    'discord_id' => $channel->id,
                ], [
                    'guild_id' => $guild->id,
                    'name' => $channel->name,
                    'type' => $channel->type,
                ]);
            });

            $discord->on(Event::CHANNEL_DELETE, function (Channel $channel, Discord $discord) {
                ChannelModel::where('discord_id', $channel->id)->delete();
            });

            $discord->on(Event::GUILD_ROLE_CREATE, function (Role $role, Discord $discord) {
                $guild = GuildModel::where('discord_id', $role->guild_id)->first();
                RoleModel::updateOrCreate([
                    'discord_id' => $role->id,
                ], [
                    'guild_id' => $guild->id,
                    'name' => $role->name,
                ]);
            });

            $discord->on(Event::GUILD_ROLE_UPDATE, function (Role $role, Discord $discord, ?Role $oldRole) {
                $guild = GuildModel::where('discord_id', $role->guild_id)->first();
                RoleModel::updateOrCreate([
                    'discord_id' => $role->id,
                ], [
                    'guild_id' => $guild->id,
                    'name' => $role->name,
                ]);
            });

            $discord->on(Event::GUILD_ROLE_DELETE, function (object $role, Discord $discord) {
                if ($role instanceof Role) {
                    RoleModel::where('discord_id', $role->id)->delete();
                }
                else {
                    dump($role);
                    RoleModel::where('discord_id', $role->role_id)->delete();
                    // {
                    //     "guild_id": "" // role guild ID
                    //     "role_id": "", // role ID,
                    // }
                }
            });


            /*$discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
                Handler::seen($message);
                Handler::message($message, $discord);
            });*/

            $discord->on(Event::MESSAGE_UPDATE, function (Message $message, Discord $discord, ?Message $oldMessage) {
                $guild = \Feniks\Bot\Models\Guild::where('discord_id', $message->guild_id)->first();
                if(! $guild) {
                    return;
                }
                $guild->audit("A message in <#{$message->channel_id}> was edited by <@{$message->user_id}>", $discord, AuditLog::NOTICE);
            });

            $discord->on(Event::MESSAGE_DELETE, function (object $message, Discord $discord) {
                $guild = \Feniks\Bot\Models\Guild::where('discord_id', $message->guild_id)->first();

                if(! $guild) {
                    return;
                }

                $userId = Cache::get('message-'.$message->id.'-author', 'unknown');
                $guild->audit("A message by <@{$userId}> in <#{$message->channel_id}> was deleted", $discord, AuditLog::WARNING);
            });

        });

        $discord->run();
        return 0;
    }

    private function registerCommands(Discord $discord)
    {

        foreach($this->commands as $class) {
            $command = new $class($discord);

            if(Cache::get("command-version-{$command->getName()}", 1) < $command->getSigV()) {
                $SlashCommand = new SlashCommand($discord, [
                    'name' => $command->getName(),
                    'description' => $command->getDescription(),
                    'options' => $command->getOptions(),
                ]);
                $discord->application->commands->save($SlashCommand);
                $discord->getLogger()->notice('Updating command '.$command->getName());
                Cache::put("command-version-{$command->getName()}", $command->getSigV());
            }



            $discord->listenCommand($command->getName(), function (Interaction $interaction) use($command) {
                $command->handle($interaction);
            });
        }
    }

    private function registerEvents(Discord $discord)
    {
        foreach($this->events as $event => $handler) {
            $discord->on($event, function () use($handler) {
                $handler = new $handler(func_get_args());
                $handler->handle();
            });
        }
    }
}
