<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;

class Ping extends Command
{

    protected $name = 'ping';
    protected $description = 'Show system status';
    protected $sigV = 2;

    public function handle(Interaction $interaction)
    {


        $redis = (new \Clue\React\Redis\Factory($this->discord->getLoop()))->createLazyClient('localhost:6379');

        $redis->info()->then(function (?string $value) use($interaction) {
            $redisInfo = [];
            $lines = explode(PHP_EOL, $value);
            foreach($lines as $line) {
                $parts = explode(':', $line);
                if(sizeof($parts) == 2) {
                    $redisInfo[$parts[0]] = $parts[1];
                }
            }
            $users = count($this->discord->users);
            $guilds = count($this->discord->guilds);
            $embed = new \Feniks\Bot\Embed($interaction->guild);
            $embed
                ->title(':robot: System information')
                ->description(":wave: I am Feniks! I am serving `{$guilds} guilds` and currently watching `{$users} users`. ")
                ->field(':computer: System',
                    "Running `PHP ". PHP_VERSION.' '.PHP_OS.' ('.php_uname('m').')`'
                )->field(':gear: DiscordPHP',
                    $this->discord::VERSION,
                    true
                )->field(':satellite: Discord API',
                    'HTTP v.'.$this->discord->http::HTTP_API_VERSION.' GW v.'.$this->discord::GATEWAY_VERSION,
                    true
                )->field(
                    ':dna: Memory Usage',
                    'Current: '.intval(memory_get_usage(true)/1024/1024).' Mb'.PHP_EOL.'Peak: '.intval(memory_get_peak_usage(true)/1024/1024).' Mb',
                    true
                )->field(
                    ':crystal_ball: Cache memory',
                    'Current: '.$redisInfo['used_memory_human'].PHP_EOL.'Peak: '.$redisInfo['used_memory_peak_human'],
                    true
                )
                ->field(
                    ':books: Cache keyspace',
                    'Current: '.$redisInfo['db0'],
                    true
                )
            ;

            $interaction->respondWithMessage(MessageBuilder::new()->addEmbed(new Embed($this->discord, $embed->toArray())));

        }, function (\Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });

    }
}