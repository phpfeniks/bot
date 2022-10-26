<?php

namespace Feniks\Bot;

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

            $discord->getLoop()->addPeriodicTimer(3600, function($timer) use($discord) {
                $this->info('Hourly tick');
                $seasons = Season::whereDate('start_date', '<=', Carbon::now('UTC'))->where('announced', false)->get();
                foreach($seasons as $season) {
                    $channel = $discord->getChannel($season->guild->settings()->get('general.announcement-channel', null));

                    if($channel) {
                        $embed = [
                            'color' => '#FEE75C',
                            'author' => [
                                'name' => 'A new season has just started',
                                'icon_url' => $season->guild->avatar
                            ],
                            "title" => $season->name,
                            "description" => $season->description,
                            'fields' =>[
                                [
                                    'name' => ':date: Season start:',
                                    'value' => $season->start_date,
                                    'inline' => true,
                                ],
                                [
                                    'name' => ':date: Season end;',
                                    'value' => $season->end_date,
                                    'inline' => true,
                                ]
                            ],
                            'footer' => array(
                                'icon_url'  => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
                                'text'  => 'Powered by Feniks',
                            ),
                        ];
                        $embed = new Embed($discord, $embed);

                        $reply = MessageBuilder::new()
                            ->addEmbed($embed)
                            ->setTts(true);

                        $channel->sendMessage($reply)->done(function (Message $reply) use($season) {
                            $season->announced = true;
                            $season->save();
                        });
                    }
                }
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
                $guild = GuildModel::where('discord_id', $interaction->guild_id)->first();
                $users = $guild->users()
                    ->orderByPivot('points', 'desc')->limit(10)->get();

                $top = [];
                $position = 1;
                $positionIcons = [
                    1 => ' :trophy: ',
                    2 => ' :second_place: ',
                    3 => ' :third_place: ',
                ];
                foreach($users as $user) {
                    if(isset($positionIcons[$position])) {
                        $top[] .= "{$positionIcons[$position]} <@{$user->discord_id}> `{$user->pivot->points} XP`";
                    } else {
                        $top[] .= "**{$position}.** <@{$user->discord_id}> `{$user->pivot->points} XP`";
                    }

                    $position++;
                }

                $top3 = implode(PHP_EOL, array_slice($top, 0, 3));
                $scores = implode(PHP_EOL, array_slice($top, 3));

                $embed = [
                    'color' => '#FEE75C',
                    'author' => [
                        'name' => $guild->name,
                        'icon_url' => $guild->avatar
                    ],
                    "title" => ":clipboard: Scoreboard",
                    "description" => "Below is the total scores for *$guild->name*",
                    'fields' =>array(
                    ),
                    'footer' => array(
                        'icon_url'  => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
                        'text'  => 'Powered by Feniks',
                    ),
                ];

                if($top3 !== '') {
                    $embed['fields'][0] = [
                        'name' => 'Top 3 :speech_balloon: ',
                        'value' => $top3,
                        'inline' => true,
                    ];
                }
                if($scores) {
                    $embed['fields'][1] = [
                        'name' => 'Honorable mentions :speech_balloon:',
                        'value' => $scores,
                        'inline' => true,
                    ];
                }

                $embed = new Embed($discord, $embed);

                $embed = $scoreboard->getEmbed();

                $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!')->addEmbed($embed));
            });

            $discord->listenCommand('ping', function (Interaction $interaction) use($discord) {
                $embed = new Embed($discord, [
                    'image' => [
                        'url' => 'https://media.discordapp.net/avatars/285852400214867969/d6184c48ac7ded282c33503543a812ea.jpg',
                    ],
                    'color' => '#FEE75C',
                    'author' => [
                        'name' => 'test',
                        'icon_url' => 'https://media.discordapp.net/avatars/285852400214867969/d6184c48ac7ded282c33503543a812ea.jpg'
                    ],
                    "title" => "Embed title",
                    "description" => "Embed description",
                    'fields' =>array(
                        '0' => array(
                            'name' => 'Fields',
                            'value' => 'They can have different fields with small headlines.',
                            'inline' => true
                        ),
                        '1' => array(
                            'name' => 'Fields',
                            'value' => 'You can put [masked links](http://google.com) inside of rich embeds.',
                            'inline' => true
                        ),
                        '2' => array(
                            'name' => 'Fields',
                            'value' => 'You can put [masked links](http://google.com) inside of rich embeds.',
                            'inline' => true
                        ),
                        '3' => array(
                            'name' => 'Fields',
                            'value' => 'You can put [masked links](http://google.com) inside of rich embeds.',
                            'inline' => false
                        ),
                        '4' => array(
                            'name' => 'Fields',
                            'value' => 'You can put [masked links](http://google.com) inside of rich embeds.',
                            'inline' => false
                        ),
                        '5' => array(
                            'name' => 'Fields',
                            'value' => 'You can put [masked links](http://google.com) inside of rich embeds.',
                            'inline' => false
                        ),
                        '6' => array(
                            'name' => 'Fields',
                            'value' => 'You can put [masked links](http://google.com) inside of rich embeds.',
                            'inline' => false
                        ),
                    ),
                    'footer' => array(
                        'icon_url'  => 'https://media.discordapp.net/avatars/285852400214867969/d6184c48ac7ded282c33503543a812ea.jpg',
                        'text'  => '© Suslik',
                    ),
                ]);

                 $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!')->addEmbed($embed));
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
