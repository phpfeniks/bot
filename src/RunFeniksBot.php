<?php

namespace Feniks\Bot;

use Feniks\Bot\Season\Overview;
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
              $overview = new Overview($interaction, $discord);
              $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($overview->all()));
            });

        });

        $discord->run();
        return 0;
    }
}
