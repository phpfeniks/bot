<?php


namespace Feniks\Bot\Commands;


use Discord\Discord;

class Command
{
    protected $name;
    protected $description;
    protected $dsicord;

    public function __construct(Discord $discord)
    {
        $this->dsicord = $discord;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }
}