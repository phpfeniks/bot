<?php


namespace Feniks\Bot\Guild;


use Discord\Parts\Guild\Guild;
use Feniks\Bot\Models\Guild as GuildModel;

class Guilds
{

  public function sync(Guild $guild)
  {
    $guildModel = GuildModel::updateOrCreate([
      'discord_id' => $guild['id'],
    ], [
      'name' => $guild['name'],
      'avatar' => $guild['icon'],
    ]);

    return $guildModel->id;
  }

}