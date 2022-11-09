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

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $logger = new Logger('DiscordPHP');
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

        $discord = new Discord([
            'token' => config('services.discord.bot_token'),
            'intents' => Intents::getDefaultIntents() | Intents::GUILDS | Intents::GUILD_MEMBERS | Intents::GUILD_PRESENCES,
            'logger' => $logger,
        ]);

        $discord->on(Event::GUILD_CREATE, function (Guild $guild, Discord $discord) {
            $this->info('Joined guild:'. $guild['name']);
            $guilds = new Guilds();
            $guildId = $guilds->sync($guild);

            $channels = new Channels();
            $channels->sync($guild, $guildId);

            $roles = new Roles();
            $roles->sync($guild, $guildId);
        });

        $discord->on('ready', function (Discord $discord) {
            $this->info('Feniks ready to fly!');
            $activity = new Activity($discord, [
                'name' => '/help',
                'type' => Activity::TYPE_LISTENING,
            ]);

            $discord->updatePresence($activity);

            $discord->getLoop()->addPeriodicTimer(3600, function($timer) use($discord) {
                $this->info('Hourly tick');

                $announcer = new Announcer($discord);
                $announcer->starting();
            });

            $discord->getLoop()->addPeriodicTimer(7200, function($timer) use($discord) {
                $this->info('Updating active guilds:');

                foreach($discord->guilds as $guild) {
                    $this->info('-Active in guild: '.$guild->id);
                    $update = GuildModel::where('discord_id', $guild->id)->first();
                    $update->active_at = now('UTC');
                    $update->save();
                }

            });

            $command = new SlashCommand($discord, [
                'name' => 'help',
                'description' => 'Get started using Feniks'
            ]);
            $discord->application->commands->save($command);

            $command = new SlashCommand($discord, [
                'name' => 'scores',
                'description' => 'Show total scores for this server'
            ]);
            $discord->application->commands->save($command);

            $command = new SlashCommand($discord, [
                'name' => 'level',
                'description' => 'Show the user information card for you or any other user.',
                'options' => [
                    new Option($discord, [
                        'name' => 'user',
                        'description' => 'User to display',
                        'required' => false,
                        'type' => Option::USER,
                        'autocomplete' => false
                    ])
                ]
            ]);
            $discord->application->commands->save($command);

            $command = new SlashCommand($discord, [
                'name' => 'season',
                'description' => 'Show scores for selected season',
                'options' => [
                    new Option($discord, [
                        'name' => 'season',
                        'description' => 'Season to show scores for (see /seasons)',
                        'required' => true,
                        'type' => Option::STRING,
                        'autocomplete' => false
                    ])
                ]
            ]);
            $discord->application->commands->save($command);

            /*$command = new SlashCommand($discord, [
                'name' => 'profile',
                'description' => 'Edit the bio and colors for your profile card',
            ]);
            $discord->application->commands->save($command);*/


            $command = new SlashCommand($discord, ['name' => 'seasons', 'description' => 'List all the seasons for this server']);
            $discord->application->commands->save($command);


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


            $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
                Handler::seen($message);
                Handler::message($message, $discord);
            });

            $discord->on(Event::MESSAGE_UPDATE, function (Message $message, Discord $discord, ?Message $oldMessage) {
                $guild = \Feniks\Bot\Models\Guild::where('discord_id', $message->guild_id)->first();
                $auditChannel = $discord->getChannel($guild->settings()->get('general.audit-channel', null));
                $auditMessage = \Feniks\Bot\Models\Message::where('discord_id', $message->id)->first();

                if($auditChannel && $auditMessage) {
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
                        ->addEmbed(new Embed($discord, $embed->toArray()))
                        ->setTts(true);

                    $auditChannel->sendMessage($reply)->done(function (Message $reply)  {

                    });
                }
            });

            $discord->on(Event::MESSAGE_DELETE, function (object $message, Discord $discord) {
                $guild = \Feniks\Bot\Models\Guild::where('discord_id', $message->guild_id)->first();
                $auditChannel = $discord->getChannel($guild->settings()->get('general.audit-channel', null));
                $auditMessage = \Feniks\Bot\Models\Message::where('discord_id', $message->id)->first();


                if($auditChannel && $auditMessage) {
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

                        $interaction->message->react(':white_check_mark:')->done(function () {

                        });

                        $interaction->respondWithMessage(MessageBuilder::new()
                            ->setContent(":white_check_mark: Removed `{$auditMessage->points} XP` from <@{$user->discord_id}>."));
                    }, $discord);

                    $row = ActionRow::new()
                        ->addComponent($button);

                    $reply = MessageBuilder::new()
                        ->addEmbed(new Embed($discord, $embed->toArray()))
                        ->addComponent($row)
                        ->setTts(true);

                    $auditChannel->sendMessage($reply)->done(function (Message $reply)  {

                    });
                }
            });

            $discord->listenCommand('scores', function (Interaction $interaction) use($discord) {
                $scoreboard = new Scoreboard($interaction, $discord);
                $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($scoreboard->getEmbed()));
            });

            $discord->listenCommand('season', function (Interaction $interaction) use($discord) {
                foreach ($interaction['data']['options'] as $option) {
                    $scoreboard = new Scoreboard($interaction, $discord, $option['value']);
                }
                $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($scoreboard->getEmbed()));
            });

            $discord->listenCommand('seasons', function (Interaction $interaction) use($discord) {
                $overview = new Overview($interaction, $discord);
                $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($overview->all()));
            });

            $discord->listenCommand('level', function (Interaction $interaction) use($discord) {
                $level = new Level($interaction, $discord);
                $showLevel = $level->showLevel();
                if(! $showLevel) {
                    $interaction->respondWithMessage(MessageBuilder::new()->setContent(':information_source:  I have no information about this user.'));
                } else {
                    $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($showLevel));
                }

            });

            $discord->listenCommand('help', function (Interaction $interaction) use($discord) {
                $embed = new \Feniks\Bot\Embed($interaction->guild);
                $embed
                    ->title(':information_source: Get started with Feniks')
                    ->description(":robot: Bip boop! I'm Feniks, nice to meet you. To see all my commands `type /` and press my avatar to the left.")
                    ->field(
                        ':ticket: Server owner?',
                        "Log in to the dashboard at [feniksbot.com](https://feniksbot.com) to get started."
                    );

                $interaction->respondWithMessage(MessageBuilder::new()->addEmbed(new Embed($discord, $embed->toArray())));
            });

       /*     $discord->listenCommand('profile', function (Interaction $interaction) use($discord) {
                $ar = ActionRow::new();
                $ti = TextInput::new('Color (hex)', TextInput::STYLE_SHORT, 'color');
                $ar->addComponent($ti);
                $ar2 = ActionRow::new();
                $ti2 = TextInput::new('About ', TextInput::STYLE_PARAGRAPH, 'about');
                $ar2->addComponent($ti2);
                $customId = '123';
                $interaction->showModal('Edit profile card', $customId, [$ar, $ar2], function (Interaction $interaction, Collection $components) {
                    var_dump($components);
                    // $components['first']->value
                    // $components['second']->value
                    $interaction->acknowledge();
                });
            });*/
        });

        $discord->run();
        return 0;
    }
}
