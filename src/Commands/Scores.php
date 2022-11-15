<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Feniks\Bot\Guild\Scoreboard;

class Scores extends Command
{
    protected $name = 'scores';
    protected $description = 'Show total scores for this server';


    public function handle(Interaction $interaction)
    {
        $scoreboard = new Scoreboard($interaction, $this->discord);
        $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($scoreboard->getEmbed()));
    }
}