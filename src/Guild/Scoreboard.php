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

    $embed = [
      'color' => '#FEE75C',
      'author' => [
        'name' => $this->guild->name,
        'icon_url' => $this->guild->avatar
      ],
      "title" => ":clipboard: Scoreboard",
      "description" => "Below is the total scores for *{$this->guild->name}*",
      'fields' => [
        0 => [
          'name' => 'Top 3 :speech_balloon: ',
          'value' => $this->top3(),
          'inline' => true,
        ],
        1 => [
          'name' => 'Honorable mentions :speech_balloon: ',
          'value' => $this->honerable() ? $this->honerable() : 'None',
          'inline' => true,
        ]
      ],
      'footer' => array(
        'icon_url'  => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
        'text'  => 'Powered by Feniks',
      ),
    ];

    dump($embed);

    return new Embed($this->discord, $embed);
  }
}
