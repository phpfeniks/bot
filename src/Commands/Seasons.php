<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Feniks\Bot\Season\Overview;

class Seasons extends Command
{
    protected $name = 'seasons';
    protected $description = 'List all the seasons for this server';

    public function handle(Interaction $interaction)
    {
        $overview = new Overview($interaction, $this->discord);
        $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($overview->all()));
    }
}