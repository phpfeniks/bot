<?php


namespace Feniks\Bot\Season;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Embed\Embed;
use Feniks\Bot\Models\Guild as GuildModel;

class Overview
{

  private $interaction;
  private $discord;

  public function __construct(Interaction $interaction, Discord $discord)
  {
    $this->interaction = $interaction;
    $this->discord = $discord;
  }

  public function all()
  {
    $guild = GuildModel::where('discord_id', $this->interaction->guild_id)->first();
    $seasons = $guild->seasons()->orderBy('end_date', 'desc')->get();

    $embed = new \Feniks\Bot\Embed($guild);
    $embed
      ->title(":clipboard: All seasons")
      ->description("Below is all past and future seasons for this server");

    foreach($seasons as $season) {
      $embed->field(
        "$season->name / {$season->start_date} to {$season->end_date}",
        "{$season->description}\n `/season {$season->id}` \n-------------------------"
      );
    }
    return new Embed($this->discord, $embed->toArray());
  }

}