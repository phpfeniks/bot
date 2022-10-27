<?php

namespace Feniks\Bot;

use Feniks\Bot\Season\Announcer;
use Feniks\Bot\Models\Channel;
use Feniks\Bot\Models\Role;
use Feniks\Bot\Models\Season;
use Feniks\Bot\Models\User;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Feniks\Bot\Models\Guild as GuildModel;
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
            $guildModel = GuildModel::updateOrCreate([
                'discord_id' => $guild['id'],
            ], [
                'name' => $guild['name'],
                'avatar' => $guild['icon'],
            ]);

            foreach($guild->channels as $channel) {
                if($channel->type == 0) {
                    Channel::updateOrCreate([
                        'discord_id' => $channel->id,
                    ], [
                        'guild_id' => $guildModel->id,
                        'name' => $channel->name,
                        'type' => $channel->type,
                    ]);
                }
            }

            foreach($guild->roles as $role) {
                Role::updateOrCreate([
                    'discord_id' => $role->id,
                ], [
                    'guild_id' => $guildModel->id,
                    'name' => $role->name,
                ]);
            }

        });

        $discord->on('ready', function (Discord $discord) {
            $this->info('Feniks ready to fly!');

            $discord->getLoop()->addPeriodicTimer(6, function($timer) use($discord) {
                $this->info('Hourly tick');

                $announcer = new Announcer($discord);
                $announcer->starting();

            });

            $command = new SlashCommand($discord, ['name' => 'ping', 'description' => 'pong']);
            $discord->application->commands->save($command);

            $command = new SlashCommand($discord, ['name' => 'scores', 'description' => 'Show current scores']);
            $discord->application->commands->save($command);

            $command = new SlashCommand($discord, ['name' => 'seasons', 'description' => 'List all the seasons for this server']);
            $discord->application->commands->save($command);

            $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
               Handler::seen($message);
               Handler::message($message, $discord);
            });



            $discord->listenCommand('scores', function (Interaction $interaction) use($discord) {
              $scoreboard = new Scoreboard($interaction, $discord);
              $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($scoreboard->getEmbed()));
            });


            $discord->listenCommand('seasons', function (Interaction $interaction) use($discord) {

                $guild = GuildModel::where('discord_id', $interaction->guild_id)->first();
                $seasons = $guild->seasons()->orderBy('end_date', 'desc')->get();

                $embed = [
                    'color' => '#FEE75C',
                    'author' => [
                        'name' => $guild->name,
                        'icon_url' => $guild->avatar
                    ],
                    "title" => ":clipboard: All seasons",
                    "description" => "Below is all past and future seasons for this server",
                    'fields' =>array(
                    ),
                    'footer' => array(
                        'icon_url'  => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
                        'text'  => 'Powered by Feniks',
                    ),
                ];

                foreach($seasons as $season) {
                    $embed['fields'][] = [
                        'name' => "$season->name / {$season->start_date} to {$season->end_date}",
                        'value' => "{$season->description}\n -------------------------",
                        'inline' => false,
                    ];

                }

                $embed = new Embed($discord, $embed);

                $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed));
            });

        });

        $discord->run();
        return 0;
    }
}
