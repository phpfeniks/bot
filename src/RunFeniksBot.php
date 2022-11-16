<?php

namespace Feniks\Bot;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\TextInput;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Role;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\User\Activity;
use Feniks\Bot\Events\MessageCreate;
use Feniks\Bot\Guild\Channels;
use Feniks\Bot\Guild\Guilds;
use Feniks\Bot\Guild\Roles;
use Feniks\Bot\Season\Overview;
use Feniks\Bot\Season\Announcer;
use Feniks\Bot\Models\Season;
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
use Feniks\Bot\Guild\Scoreboard;
use Feniks\Bot\User\Level;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

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

        $logger = Log::channel('discord')->getLogger();
        $discord = new Discord([
            'token' => config('services.discord.bot_token'),
            'intents' => Intents::getDefaultIntents() | Intents::GUILDS,
            'logger' => $logger,
        ]);

        $discord->on(Event::GUILD_CREATE, function (Guild $guild, Discord $discord) {
            $discord->getLogger()->info('Joined guild:'. $guild['name']);
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
                $discord->getLogger()->info('Hourly tick');

                $announcer = new Announcer($discord);
                $announcer->starting();
            });

            $discord->getLoop()->addPeriodicTimer(7200, function($timer) use($discord) {
                $discord->getLogger()->info('Updating active guilds');
                foreach($discord->guilds as $guild) {
                    $discord->getLogger()->info('Active in guild: '.$guild->id);
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
                $auditChannel = $discord->getChannel($guild->settings()->get('general.audit-channel', null));
                $auditMessage = \Feniks\Bot\Models\Message::where('discord_id', $message->id)->first();

                if($auditChannel && $auditMessage) {

                    if(! $auditChannel->getBotPermissions()->view_channel || ! $auditChannel->getBotPermissions()->send_messages ||  ! $auditChannel->getBotPermissions()->embed_links) {
                        $discord->getLogger()->warning('No access to audit channel for guild '. $guild->discord_id);
                        return;
                    }

                    if ($message instanceof Message) {
                        // Message is present in cache
                    }
                    // If the message is not present in the cache:
                    else {
                        $user = User::where('id', $auditMessage->user_id)->first();
                        $message->user_id = $user->discord_id;

                    }
                    $lengthDifference = strlen($message->content) - $auditMessage->length;

                    $embed = new \Feniks\Bot\Embed($guild);
                    $embed
                        ->title(':pencil: Message edited')
                        ->description("A message in <#{$message->channel_id}> was edited by <@{$message->user_id}>")
                        ->field(
                            'New message',
                            "`{$message->content}`"
                        )
                        ->field(
                            ':speech_balloon: Length difference',
                            "`{$lengthDifference} chars`",
                            true
                        )
                        ->field(
                            ':bar_chart: Points awarded',
                            "`{$auditMessage->points} XP`",
                            true
                        );

                    $reply = MessageBuilder::new()
                        ->addEmbed(new Embed($discord, $embed->toArray()));

                    $auditChannel->sendMessage($reply)->done(function (Message $reply)  {

                    });
                }
            });

            $discord->on(Event::MESSAGE_DELETE, function (object $message, Discord $discord) {
                $guild = \Feniks\Bot\Models\Guild::where('discord_id', $message->guild_id)->first();
                $auditChannel = $discord->getChannel($guild->settings()->get('general.audit-channel', null));
                $auditMessage = \Feniks\Bot\Models\Message::where('discord_id', $message->id)->first();


                if($auditChannel && $auditMessage) {

                    if(! $auditChannel->getBotPermissions()->view_channel || ! $auditChannel->getBotPermissions()->send_messages ||  ! $auditChannel->getBotPermissions()->embed_links) {
                        $discord->getLogger()->warning('No access to audit channel for guild '. $guild->discord_id);
                        return;
                    }

                    if ($message instanceof Message) {
                        // Message is present in cache
                    }
                    // If the message is not present in the cache:
                    else {
                        $user = User::where('id', $auditMessage->user_id)->first();
                        $message->user_id = $user->discord_id;

                    }


                    $embed = new \Feniks\Bot\Embed($guild);
                    $embed
                        ->title(':x: Message deleted')
                        ->description("A message in <#{$message->channel_id}> was deleted by <@{$message->user_id}>")
                        ->field(
                            'Message',
                            isset($message->content) ? "`{$message->content}`" : '`-`'
                        )
                        ->field(
                            ':speech_balloon: Message length',
                            "`{$auditMessage->length} chars`",
                            true
                        )
                        ->field(
                            ':bar_chart: Points awarded',
                            "`{$auditMessage->points} XP`",
                            true
                        );

                    $button = Button::new(Button::STYLE_DANGER)
                        ->setLabel('Remove points');
                    $button->setListener(function (Interaction $interaction) use($auditMessage) {
                        $user = User::where('id', $auditMessage->user_id)->first();
                        $userguild = $user->guilds()->where('guilds.id', $auditMessage->guild_id)->first();

                        $userguild->pivot->points = $userguild->pivot->points - $auditMessage->points;
                        $userguild->pivot->save();

                        $interaction->message->react('✅')->done(function () {

                        });

                        $interaction->respondWithMessage(MessageBuilder::new()
                            ->setContent(":white_check_mark: Removed `{$auditMessage->points} XP` from <@{$user->discord_id}>."));
                    }, $discord);

                    $row = ActionRow::new()
                        ->addComponent($button);

                    $reply = MessageBuilder::new()
                        ->addEmbed(new Embed($discord, $embed->toArray()))
                        ->addComponent($row);

                    $auditChannel->sendMessage($reply)->done(function (Message $reply)  {

                    });
                }
            });

        });

        $discord->run();
        return 0;
    }

    private function registerCommands(Discord $discord)
    {
        foreach($this->commands as $class) {
            $command = new $class($discord);
            $SlashCommand = new SlashCommand($discord, [
                'name' => $command->getName(),
                'description' => $command->getDescription(),
                'options' => $command->getOptions(),
            ]);
            $discord->application->commands->save($SlashCommand);

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
