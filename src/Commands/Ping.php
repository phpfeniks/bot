<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;

class Ping extends Command
{

    protected $name = 'ping';

    protected $description = 'pong';

    public function handle(Interaction $interaction)
    {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!'));
    }
}