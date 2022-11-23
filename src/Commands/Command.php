<?php


namespace Feniks\Bot\Commands;


use Discord\Discord;
use Discord\Parts\Interactions\Command\Option;

class Command
{
    protected $name;
    protected $description;
    protected $options;
    protected $discord;
    protected $sigV = 1;

    public function __construct(Discord $discord)
    {
        $this->discord = $discord;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getSigV()
    {
        return $this->sigV;
    }


    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        if(! $this->options) {
            return null;
        }

        $options = [];
        foreach($this->options as $option) {
            $options[] = new Option($this->discord, $option);
        }
        return $this->options;
    }
}