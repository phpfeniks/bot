<?php

namespace Feniks\Bot;

use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Role;
use Discord\Parts\Interactions\Command\Option;
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
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
        $discord = new Discord([
            'token' => config('services.discord.bot_token'),
            'intents' => Intents::getDefaultIntents() | Intents::GUILDS | Intents::GUILD_MEMBERS | Intents::GUILD_PRESENCES,
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

            $discord->getLoop()->addPeriodicTimer(3600, function($timer) use($discord) {
                $this->info('Hourly tick');

                $announcer = new Announcer($discord);
                $announcer->starting();
            });

            $command = new SlashCommand($discord, [
                'name' => 'scores',
                'description' => 'Show total scores for this server'
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
                    'name' => $channel->name,
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

        });

        $discord->run();
        return 0;
    }
}
