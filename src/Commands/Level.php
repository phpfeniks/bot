<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;

class Level extends Command
{
    protected $name = 'level';
    protected $description = 'Show the user information card for you or any other user';
    protected $options = [
        [
            'name' => 'user',
            'description' => 'User to display',
            'required' => false,
            'type' => Option::USER,
            'autocomplete' => false
        ]
    ];
    public function handle(Interaction $interaction)
    {
        $level = new \Feniks\Bot\User\Level($interaction, $this->discord);
        $showLevel = $level->showLevel();
        if(! $showLevel) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent(':information_source:  I have no information about this user.'));
        }
    }
}