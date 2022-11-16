<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Feniks\Bot\Guild\Scoreboard;

class Season extends Command
{
    protected $name = 'season';
    protected $description = 'Show scores for selected season';
    protected $options = [
        [
            'name' => 'season',
            'description' => 'Season to show scores for (see /seasons)',
            'required' => true,
            'type' => Option::STRING,
            'autocomplete' => false
        ]
    ];

    public function handle(Interaction $interaction)
    {
        foreach ($interaction['data']['options'] as $option) {
            $scoreboard = new Scoreboard($interaction, $this->discord, $option['value']);
        }
        $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($scoreboard->getEmbed()));
    }
}