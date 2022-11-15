<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;

class Help extends Command
{
    protected $name = 'help';
    protected $description = 'Get started using Feniks';

    public function handle(Interaction $interaction)
    {
        $embed = new \Feniks\Bot\Embed($interaction->guild);
        $embed
            ->title(':information_source: Get started with Feniks')
            ->description(":robot: Bip boop! I'm Feniks, nice to meet you. To see all my commands `type /` and press my avatar to the left.")
            ->field(
                ':ticket: Server owner?',
                "Log in to the dashboard at [feniksbot.com](https://feniksbot.com) to get started."
            );

        $interaction->respondWithMessage(MessageBuilder::new()->addEmbed(new Embed($this->discord, $embed->toArray())));
    }

}