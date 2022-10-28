<?php


namespace Feniks\Bot\Guild;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Feniks\Bot\Models\Guild as GuildModel;
use Discord\Parts\Interactions\Interaction;
use Feniks\Bot\Models\Season;

class Scoreboard
{
  private $guild;
  private $discord;
  private $season;
  private $scoreboard = [];

  public function __construct(Interaction $interaction, Discord $discord, $season = null)
  {
    $this->guild = GuildModel::where('discord_id', $interaction->guild_id)->first();
    $this->discord = $discord;
    $this->season = $season;

    if($season !== null ) {
      $this->season = Season::where('id', $season)->first();
      if(! $this->season) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent(':red_square: **No such season**!* Check `/seasons` for available seasons on this server.'));
      }
    }

    dump($this->season);
  }

  private function getUsers()
  {

    if($this->season !== null) {
      return $this->season->users()
        ->orderByPivot('points', 'desc')->limit(13)->get();
    }
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

    if($this->season === null) {
      $embed
        ->title(':clipboard: Scoreboard')
        ->description("Below is the total scores for *{$this->guild->name}*");
    } else {
      $embed
        ->title(':clipboard: Season scoreboard')
        ->description("Below is the scores for *{$this->season->name}*");
    }


    $embed
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
