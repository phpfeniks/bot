<?php


namespace Feniks\Bot\Guild;

use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Feniks\Bot\Models\Guild as GuildModel;
use Discord\Parts\Interactions\Interaction;

class Scoreboard
{
  private $guild;
  private $discord;
  private $scoreboard = [];

  public function __construct(Interaction $interaction, Discord $discord, $season = null)
  {
    $this->guild = GuildModel::where('discord_id', $interaction->guild_id)->first();
    $this->discord = $discord;
  }

  private function getUsers()
  {
    return $this->guild->users()
      ->orderByPivot('points', 'desc')->limit(13)->get();
  }

  private function create()
  {
    $position = 1;
    $positionIcons = [
      1 => ' :trophy: ',
      2 => ' :second_place: ',
      3 => ' :third_place: ',
    ];
    foreach($this->getUsers() as $user) {
      if(isset($positionIcons[$position])) {
        $this->scoreboard[] .= "{$positionIcons[$position]} <@{$user->discord_id}> `{$user->pivot->points} XP`";
      } else {
        $this->scoreboard[] .= "**{$position}.** <@{$user->discord_id}> `{$user->pivot->points} XP`";
      }

      $position++;
    }
    dump($this->scoreboard);
  }

  private function top3()
  {
    return implode(PHP_EOL, array_slice($this->scoreboard, 0, 3));

  }

  private function honerable()
  {
    return implode(PHP_EOL, array_slice($this->scoreboard, 3));
  }

  public function getEmbed()
  {
    $this->create();

    $embed = new \Feniks\Bot\Embed($this->guild);

    $embed
      ->title(':clipboard: Scoreboard')
      ->description("Below is the total scores for *{$this->guild->name}*")
      ->field(
        'Top 3 :speech_balloon:',
        $this->top3(),
        true
      )
      ->field(
        'Honorable mentions :speech_balloon:',
        $this->honerable() ? $this->honerable() : 'None',
        true
      );

    return new Embed($this->discord, $embed->toArray());
  }
}
